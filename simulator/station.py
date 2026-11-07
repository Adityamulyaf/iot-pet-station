"""A bounded, persistent MQTT station simulator. All actuator timing is local."""
import argparse
import json
import os
import random
import signal
import threading
import tempfile
import time
import uuid
from datetime import datetime, timezone
from pathlib import Path

import paho.mqtt.client as mqtt


def utc():
    return datetime.now(timezone.utc).isoformat()


class Station:
    SCENARIOS = {"normal", "low_water", "invalid_sensor", "offline", "delayed", "no_result", "duplicate"}

    def __init__(self, device, username, password, host="127.0.0.1", port=1883, state_dir=None):
        self.device = device
        self.path = Path(state_dir or Path(__file__).parent.parent / ".runtime" / "simulator") / f"{device}.json"
        self.path.parent.mkdir(parents=True, exist_ok=True)
        self.data = {"food_g": 840.0, "water_pct": 78.0, "scenario": "normal", "processed": {}, "pending": {}, "cooldown_until": 0}
        if self.path.exists():
            self.data.update(json.loads(self.path.read_text()))
        self.lock = threading.RLock()
        self.busy = False
        self.active_command = None
        self.running = True
        self.host, self.port = host, port
        self.client = mqtt.Client(mqtt.CallbackAPIVersion.VERSION2, client_id=f"sim-{device}")
        self.client.username_pw_set(username, password)
        self.client.will_set(self.topic("state"), json.dumps(self.envelope({"online": False, "servo": "unknown", "pump": "unknown"})), qos=1, retain=True)
        self.client.on_connect = self.on_connect
        self.client.on_message = self.on_message
        self.client.reconnect_delay_set(1, 10)

    def topic(self, kind):
        return f"petstation/{self.device}/{kind}"

    def envelope(self, data):
        return {"schema_version": 1, "device_id": self.device, "message_id": str(uuid.uuid4()), "recorded_at": utc(), **data}

    def save(self):
        with self.lock:
            with tempfile.NamedTemporaryFile(mode="w", dir=self.path.parent, suffix=".tmp", delete=False) as stream:
                stream.write(json.dumps(self.data))
                stream.flush()
                os.fsync(stream.fileno())
                temp = Path(stream.name)
            try:
                for attempt in range(10):
                    try:
                        os.replace(temp, self.path)
                        break
                    except PermissionError:
                        # OneDrive and Windows scanners may briefly lock the destination.
                        if attempt == 9:
                            raise
                        time.sleep(.05 * (attempt + 1))
            finally:
                temp.unlink(missing_ok=True)

    def send(self, kind, data, retain=False):
        with self.lock:
            payload = self.envelope(data)
            self.data["pending"][payload["message_id"]] = {"kind": kind, "payload": payload, "retain": retain}
            # Bound the offline queue. State and results take priority over old telemetry.
            if len(self.data["pending"]) > 500:
                old = next((key for key, value in self.data["pending"].items() if value["kind"] == "telemetry"), next(iter(self.data["pending"])))
                self.data["pending"].pop(old)
            self.save()
            self.client.publish(self.topic(kind), json.dumps(payload), qos=1, retain=retain)
            if self.data["scenario"] == "duplicate":
                self.client.publish(self.topic(kind), json.dumps(payload), qos=1, retain=retain)

    def state(self):
        self.send("state", {"online": self.data["scenario"] != "offline", "servo": "open" if self.busy and self.active_command == "feed" else "closed", "pump": "on" if self.busy and self.active_command == "water" else "off"}, retain=True)

    def sensors(self):
        return {"food_g": round(self.data["food_g"], 1), "water_pct": round(self.data["water_pct"], 1), "food_raw": round(self.data["food_g"] * 430), "water_raw": round(self.data["water_pct"] * 40.95), "food_valid": self.data["scenario"] != "invalid_sensor", "water_valid": self.data["scenario"] != "invalid_sensor", "pir": random.random() < 0.2, "servo": "open" if self.busy and self.active_command == "feed" else "closed", "pump": "on" if self.busy and self.active_command == "water" else "off"}

    def telemetry(self):
        self.send("telemetry", self.sensors())

    def on_connect(self, client, userdata, flags, reason_code, properties):
        if reason_code.is_failure:
            print(f"{self.device}: connection refused ({reason_code})", flush=True)
            return
        client.subscribe([(self.topic("command"), 1), (self.topic("ack"), 1)])
        print(f"{self.device}: connected", flush=True)
        self.state()
        with self.lock:
            for result in list(self.data["processed"].values()):
                if result["status"] != "running":
                    self.send("result", result)

    def on_message(self, client, userdata, message):
        try:
            payload = json.loads(message.payload)
            if message.topic.endswith("/ack"):
                with self.lock:
                    self.data["pending"].pop(payload.get("message_id"), None)
                    self.save()
                return
            if message.retain or payload.get("device_id") != self.device or payload.get("schema_version") != 1:
                return
            threading.Thread(target=self.execute, args=(payload,), daemon=True).start()
        except (ValueError, KeyError, TypeError) as error:
            print(f"{self.device}: invalid message: {error}", flush=True)

    def result(self, command_id, status, reason=None, **extra):
        payload = {"command_id": command_id, "status": status, "reason": reason, **extra}
        with self.lock:
            self.data["processed"][command_id] = payload
            self.save()
        self.send("result", payload)

    def execute(self, payload):
        command_id = payload.get("command_id")
        if not command_id:
            return
        with self.lock:
            if command_id in self.data["processed"]:
                self.send("result", self.data["processed"][command_id])
                return
            expires = datetime.fromisoformat(payload["expires_at"].replace("Z", "+00:00"))
            action = payload.get("action")
            if expires <= datetime.now(timezone.utc):
                self.result(command_id, "rejected", "Perintah sudah kedaluwarsa.")
                return
            if self.busy:
                self.result(command_id, "rejected", "Aktuator masih berjalan.")
                return
            if action == "scenario":
                scenario = payload.get("parameters", {}).get("scenario")
                if scenario not in self.SCENARIOS:
                    self.result(command_id, "rejected", "Skenario tidak dikenal.")
                    return
                self.data["scenario"] = scenario
                self.data["water_pct"] = 8.0 if scenario == "low_water" else 78.0
                self.data["food_g"] = 840.0
                self.data["cooldown_until"] = 0
                self.result(command_id, "completed", "Skenario diperbarui.")
                self.state()
                if scenario != "offline":
                    self.telemetry()
                if scenario == "normal":
                    for result in list(self.data["processed"].values()):
                        if result["status"] != "running":
                            self.send("result", result)
                return
            if action not in {"feed", "water"}:
                self.result(command_id, "rejected", "Tindakan tidak dikenal.")
                return
            if self.data["scenario"] == "offline":
                return
            if self.data["scenario"] == "invalid_sensor":
                self.result(command_id, "rejected", "Pembacaan sensor tidak valid.")
                return
            if (action == "water" and self.data["water_pct"] < 20) or (action == "feed" and self.data["food_g"] < 30):
                self.result(command_id, "rejected", "Persediaan reservoir rendah.")
                return
            if time.time() < self.data["cooldown_until"]:
                self.result(command_id, "rejected", "Cooldown belum selesai.")
                return
            before = self.sensors()
            duration = int(payload.get("parameters", {}).get("duration_ms", 2000))
            if duration < 500 or duration > 3000:
                self.result(command_id, "rejected", "Durasi di luar batas perangkat.")
                return
            self.busy, self.active_command = True, action
            # Persist before execution. A restart must never execute this ID again.
            self.result(command_id, "running", before=before)
            self.state()
        try:
            time.sleep(duration / 1000)
            with self.lock:
                if action == "feed":
                    self.data["food_g"] = max(0, self.data["food_g"] - 25)
                else:
                    self.data["water_pct"] = max(0, self.data["water_pct"] - 4)
                self.data["cooldown_until"] = time.time() + 10
        finally:
            self.busy, self.active_command = False, None
            self.state()
            self.telemetry()
        if self.data["scenario"] == "delayed":
            time.sleep(40)
        if self.data["scenario"] == "no_result":
            with self.lock:
                self.data["processed"][command_id] = {"command_id": command_id, "status": "completed", "reason": None, "duration_ms": duration, "before": before, "after": self.sensors()}
                self.save()
            return
        self.result(command_id, "completed", duration_ms=duration, before=before, after=self.sensors())

    def run(self):
        # A crash during execution leaves an unresolved ID; report failure instead of replaying it.
        for key, value in list(self.data["processed"].items()):
            if value["status"] == "running":
                value.update(status="failed", reason="Simulator dimulai ulang sebelum hasil terkonfirmasi.")
        self.save()
        self.client.connect_async(self.host, self.port, keepalive=15)
        self.client.loop_start()
        last_retry = 0
        try:
            while self.running:
                if self.client.is_connected() and self.data["scenario"] != "offline":
                    self.telemetry()
                    if time.time() - last_retry >= 10:
                        with self.lock:
                            for item in list(self.data["pending"].values()):
                                self.client.publish(self.topic(item["kind"]), json.dumps(item["payload"]), qos=1, retain=item["retain"])
                        last_retry = time.time()
                time.sleep(5)
        finally:
            # Stop local outputs before disconnecting, independent of network.
            self.busy = False
            self.send("state", {"online": False, "servo": "closed", "pump": "off"}, retain=True)
            self.client.disconnect()
            self.client.loop_stop()


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--device", default="station-001")
    parser.add_argument("--host", default=os.getenv("MQTT_HOST", "127.0.0.1"))
    parser.add_argument("--port", type=int, default=int(os.getenv("MQTT_PORT", "1883")))
    args = parser.parse_args()
    credentials = json.loads((Path(__file__).parent.parent / ".runtime" / "credentials.json").read_text())
    if args.device not in credentials["devices"]:
        parser.error("Device must be provisioned in .runtime/credentials.json")
    station = Station(args.device, args.device, credentials["devices"][args.device], args.host, args.port)
    signal.signal(signal.SIGINT, lambda *_: setattr(station, "running", False))
    station.run()
