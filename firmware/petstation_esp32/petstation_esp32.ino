// Petstation ESP32 firmware. Mengikuti kontrak MQTT yang sama dengan simulator/station.py.
// Library: HX711 (bogde), ESP32Servo, PubSubClient (Nick O'Leary), ArduinoJson 7.
#include <WiFi.h>
#include <WiFiClientSecure.h>
#include <PubSubClient.h>
#include <ArduinoJson.h>
#include <HX711.h>
#include <ESP32Servo.h>
#include <Preferences.h>
#include <time.h>
#include <sys/time.h>
#include "secrets.h"

// MQTT_TLS 1: broker cloud (port 8883) diverifikasi dengan MQTT_CA_CERT dari secrets.h.
#ifndef MQTT_TLS
#define MQTT_TLS 0
#endif

// ---------- pin ----------
const int PIN_PIR = 13;
const int PIN_HX711_DT = 19;
const int PIN_HX711_SCK = 21;
const int PIN_WATER = 34;
const int PIN_PUMP = 26;
const int PIN_SERVO = 27;

// ---------- aturan lokal (sama dengan simulator) ----------
const int SERVO_CLOSED_DEG = 0;
const int SERVO_OPEN_DEG = 90;
const uint32_t TELEMETRY_MS = 5000;
const uint32_t COOLDOWN_MS = 10000;
const uint32_t MIN_DURATION_MS = 500;
const uint32_t MAX_DURATION_MS = 3000;
const uint32_t SETTLE_MS = 1500;          // tunggu timbangan stabil sebelum pembacaan "after"
// Sensor di wadah: tolak pengisian saat wadah sudah penuh (samakan dengan config/station.php).
const float FOOD_FULL_G = 200;
const float WATER_FULL_PCT = 90;
const uint32_t RESEND_MS = 10000;

HX711 scale;
Servo servo;
Preferences prefs;
#if MQTT_TLS
WiFiClientSecure net;
#else
WiFiClient net;
#endif
PubSubClient mqtt(net);

// kalibrasi (disimpan di NVS)
long hxOffset = 0;
float hxScale = 420.0;   // raw per gram, ganti lewat perintah serial "cal <gram>"
int waterDry = 0;        // ADC saat sensor kering
int waterFull = 1800;    // ADC saat sensor terendam penuh

// sensor terakhir
struct Reading { float foodG; long foodRaw; bool foodValid; float waterPct; int waterRaw; bool waterValid; };
Reading last = {0, 0, false, 0, 0, false};
bool pirLatched = false;

// eksekusi
enum Phase { IDLE, RUNNING, SETTLING };
Phase phase = IDLE;
String activeId, activeAction;
uint32_t phaseStart = 0, activeDuration = 0, cooldownUntil = 0;
bool cooldownSet = false;
String beforeJson;

// deduplikasi perintah (ring buffer di RAM + ID terakhir di NVS)
const int DEDUP_SIZE = 16;
String seenIds[DEDUP_SIZE];
String seenResults[DEDUP_SIZE];
int seenNext = 0;

// hasil akhir yang menunggu ACK aplikasi
String pendingTopic, pendingPayload, pendingMessageId;
uint32_t pendingSentAt = 0;

uint32_t lastTelemetry = 0, lastReconnect = 0;
String interruptedId;   // perintah yang terputus karena restart

// ---------- util ----------
String topic(const char *kind) { return String("petstation/") + DEVICE_ID + "/" + kind; }

String uuid4() {
  uint8_t b[16];
  for (int i = 0; i < 16; i += 4) { uint32_t r = esp_random(); memcpy(b + i, &r, 4); }
  b[6] = (b[6] & 0x0F) | 0x40;
  b[8] = (b[8] & 0x3F) | 0x80;
  char s[37];
  snprintf(s, sizeof(s), "%02x%02x%02x%02x-%02x%02x-%02x%02x-%02x%02x-%02x%02x%02x%02x%02x%02x",
           b[0], b[1], b[2], b[3], b[4], b[5], b[6], b[7], b[8], b[9], b[10], b[11], b[12], b[13], b[14], b[15]);
  return String(s);
}

bool timeReady() { return time(nullptr) > 1700000000; }

String isoNow() {
  struct timeval tv; gettimeofday(&tv, nullptr);
  struct tm t; gmtime_r(&tv.tv_sec, &t);
  char s[32];
  snprintf(s, sizeof(s), "%04d-%02d-%02dT%02d:%02d:%02d.%03ldZ", t.tm_year + 1900, t.tm_mon + 1, t.tm_mday,
           t.tm_hour, t.tm_min, t.tm_sec, (long)(tv.tv_usec / 1000));
  return String(s);
}

// "2026-11-11T10:00:00+00:00" atau "...Z" -> epoch UTC. TZ sistem = UTC (configTime 0,0).
time_t parseIso(const char *s) {
  struct tm t = {};
  if (sscanf(s, "%d-%d-%dT%d:%d:%d", &t.tm_year, &t.tm_mon, &t.tm_mday, &t.tm_hour, &t.tm_min, &t.tm_sec) != 6) return 0;
  t.tm_year -= 1900; t.tm_mon -= 1;
  time_t epoch = mktime(&t);
  const char *tz = s + 19;
  while (*tz == '.' || isdigit((unsigned char)*tz)) tz++;
  if (*tz == '+' || *tz == '-') {
    int hh = 0, mm = 0;
    sscanf(tz + 1, "%d:%d", &hh, &mm);
    long off = (hh * 3600L + mm * 60L) * (*tz == '+' ? 1 : -1);
    epoch -= off;
  }
  return epoch;
}

void envelope(JsonDocument &doc) {
  doc["schema_version"] = 1;
  doc["device_id"] = DEVICE_ID;
  doc["message_id"] = uuid4();
  doc["recorded_at"] = isoNow();
}

const char *servoState() { return (phase == RUNNING && activeAction == "feed") ? "open" : "closed"; }
const char *pumpState() { return (phase == RUNNING && activeAction == "water") ? "on" : "off"; }

// ---------- sensor ----------
void readSensors() {
  if (scale.wait_ready_timeout(300)) {
    last.foodRaw = scale.read_average(3);
    float grams = (last.foodRaw - hxOffset) / hxScale;
    last.foodValid = grams > -50 && grams < 20000;
    last.foodG = grams < 0 ? 0 : grams;
  } else {
    last.foodValid = false;
  }
  long sum = 0;
  for (int i = 0; i < 10; i++) { sum += analogRead(PIN_WATER); delay(2); }
  last.waterRaw = sum / 10;
  last.waterValid = waterFull - waterDry >= 100;
  float pct = last.waterValid ? (last.waterRaw - waterDry) * 100.0 / (waterFull - waterDry) : 0;
  last.waterPct = constrain(pct, 0, 100);
}

void sensorFields(JsonObject o) {
  o["food_g"] = round(last.foodG * 10) / 10.0;
  o["water_pct"] = round(last.waterPct * 10) / 10.0;
  o["food_raw"] = last.foodRaw;
  o["water_raw"] = last.waterRaw;
  o["food_valid"] = last.foodValid;
  o["water_valid"] = last.waterValid;
  o["pir"] = pirLatched || digitalRead(PIN_PIR) == HIGH;
  o["servo"] = servoState();
  o["pump"] = pumpState();
}

// ---------- publish ----------
bool publishDoc(const char *kind, JsonDocument &doc, bool retain = false) {
  String out; serializeJson(doc, out);
  return mqtt.publish(topic(kind).c_str(), out.c_str(), retain);
}

void publishState(bool online = true) {
  JsonDocument doc; envelope(doc);
  doc["online"] = online;
  doc["servo"] = servoState();
  doc["pump"] = pumpState();
  publishDoc("state", doc, true);
}

void publishTelemetry() {
  readSensors();
  JsonDocument doc; envelope(doc);
  sensorFields(doc.as<JsonObject>());
  if (publishDoc("telemetry", doc)) pirLatched = false;
}

void remember(const String &id, const String &resultJson) {
  seenIds[seenNext] = id;
  seenResults[seenNext] = resultJson;
  seenNext = (seenNext + 1) % DEDUP_SIZE;
  prefs.putString("last_cmd", id);
}

int seenIndex(const String &id) {
  for (int i = 0; i < DEDUP_SIZE; i++) if (seenIds[i] == id) return i;
  return -1;
}

void publishResult(const String &commandId, const char *status, const char *reason,
                   uint32_t durationMs = 0, const String &before = "", const String &after = "") {
  JsonDocument doc; envelope(doc);
  doc["command_id"] = commandId;
  doc["status"] = status;
  if (reason) doc["reason"] = reason; else doc["reason"] = nullptr;
  if (durationMs) doc["duration_ms"] = durationMs;
  if (before.length()) { JsonDocument b; deserializeJson(b, before); doc["before"] = b; }
  if (after.length()) { JsonDocument a; deserializeJson(a, after); doc["after"] = a; }
  String out; serializeJson(doc, out);
  mqtt.publish(topic("result").c_str(), out.c_str());
  if (strcmp(status, "running") != 0) {
    remember(commandId, out);
    pendingTopic = topic("result");
    pendingPayload = out;
    pendingMessageId = doc["message_id"].as<String>();
    pendingSentAt = millis();
  }
}

String snapshotJson() {
  JsonDocument doc;
  sensorFields(doc.to<JsonObject>());
  String out; serializeJson(doc, out);
  return out;
}

// ---------- aktuator ----------
void stopActuators() {
  digitalWrite(PIN_PUMP, LOW);
  servo.write(SERVO_CLOSED_DEG);
}

void startCommand(const String &id, const String &action, uint32_t duration) {
  readSensors();
  beforeJson = snapshotJson();
  activeId = id; activeAction = action; activeDuration = duration;
  phase = RUNNING; phaseStart = millis();
  prefs.putString("running", id);   // restart saat berjalan dilaporkan gagal, tidak diulang
  if (action == "feed") servo.write(SERVO_OPEN_DEG); else digitalWrite(PIN_PUMP, HIGH);
  publishResult(id, "running", nullptr, 0, beforeJson);
  publishState();
}

void tickExecution() {
  if (phase == RUNNING && millis() - phaseStart >= activeDuration) {
    stopActuators();
    phase = SETTLING; phaseStart = millis();
    cooldownUntil = millis() + COOLDOWN_MS; cooldownSet = true;
    publishState();
  }
  if (phase == SETTLING && millis() - phaseStart >= SETTLE_MS) {
    readSensors();
    String after = snapshotJson();
    phase = IDLE;
    prefs.remove("running");
    publishResult(activeId, "completed", nullptr, activeDuration, beforeJson, after);
    publishTelemetry();
    lastTelemetry = millis();
  }
}

void onCommand(JsonDocument &cmd) {
  String id = cmd["command_id"] | "";
  if (id.isEmpty()) return;
  int seen = seenIndex(id);
  if (seen >= 0) { mqtt.publish(topic("result").c_str(), seenResults[seen].c_str()); return; }
  if (id == activeId && phase != IDLE) return;
  if (id == prefs.getString("last_cmd", "")) { publishResult(id, "rejected", "Perintah sudah diproses sebelumnya."); return; }

  String action = cmd["action"] | "";
  const char *expires = cmd["expires_at"] | "";
  if (!timeReady() || parseIso(expires) <= time(nullptr)) { publishResult(id, "rejected", "Perintah sudah kedaluwarsa."); return; }
  if (phase != IDLE) { publishResult(id, "rejected", "Aktuator masih berjalan."); return; }
  if (action == "scenario") { publishResult(id, "rejected", "Skenario hanya tersedia untuk simulator."); return; }
  if (action != "feed" && action != "water") { publishResult(id, "rejected", "Tindakan tidak dikenal."); return; }
  readSensors();
  if (!last.foodValid || !last.waterValid) { publishResult(id, "rejected", "Pembacaan sensor tidak valid."); return; }
  if ((action == "water" && last.waterPct >= WATER_FULL_PCT) || (action == "feed" && last.foodG >= FOOD_FULL_G)) {
    publishResult(id, "rejected", "Wadah sudah penuh."); return;
  }
  if (cooldownSet && (int32_t)(cooldownUntil - millis()) > 0) { publishResult(id, "rejected", "Cooldown belum selesai."); return; }
  uint32_t duration = cmd["parameters"]["duration_ms"] | 2000;
  if (duration < MIN_DURATION_MS || duration > MAX_DURATION_MS) { publishResult(id, "rejected", "Durasi di luar batas perangkat."); return; }
  startCommand(id, action, duration);
}

void onMessage(char *t, byte *payload, unsigned int len) {
  String tp(t);
  JsonDocument doc;
  if (deserializeJson(doc, payload, len)) return;
  if (tp.endsWith("/ack")) {
    if (pendingMessageId.length() && pendingMessageId == doc["message_id"].as<String>()) { pendingPayload = ""; pendingMessageId = ""; }
    return;
  }
  if (doc["device_id"] != DEVICE_ID || doc["schema_version"] != 1) return;
  onCommand(doc);
}

// ---------- jaringan ----------
void connectMqtt() {
  JsonDocument will; envelope(will);
  will["online"] = false; will["servo"] = "unknown"; will["pump"] = "unknown";
  String willStr; serializeJson(will, willStr);
  Serial.print("MQTT connect... ");
  if (mqtt.connect(DEVICE_ID, DEVICE_ID, MQTT_PASSWORD, topic("state").c_str(), 1, true, willStr.c_str(), true)) {
    Serial.println("ok");
    mqtt.subscribe(topic("command").c_str(), 1);
    mqtt.subscribe(topic("ack").c_str(), 1);
    publishState();
    if (interruptedId.length()) { publishResult(interruptedId, "failed", "Perangkat dimulai ulang sebelum hasil terkonfirmasi."); interruptedId = ""; }
    if (pendingPayload.length()) { mqtt.publish(pendingTopic.c_str(), pendingPayload.c_str()); pendingSentAt = millis(); }
  } else {
    Serial.printf("gagal rc=%d\n", mqtt.state());
  }
}

void ensureNetwork() {
  // Jangan memblokir loop selama aktuator berjalan; aktuator tetap berhenti sesuai waktu lokal.
  if (phase == RUNNING) return;
  if (WiFi.status() != WL_CONNECTED) {
    if (millis() - lastReconnect > 10000) { lastReconnect = millis(); WiFi.disconnect(); WiFi.begin(WIFI_SSID, WIFI_PASSWORD); Serial.println("WiFi reconnect..."); }
    return;
  }
  if (!timeReady()) return;
  if (!mqtt.connected() && (lastReconnect == 0 || millis() - lastReconnect > 5000)) { lastReconnect = millis(); connectMqtt(); }
}

// ---------- kalibrasi lewat Serial ----------
void saveCalibration() {
  prefs.putLong("hx_off", hxOffset); prefs.putFloat("hx_scale", hxScale);
  prefs.putInt("w_dry", waterDry); prefs.putInt("w_full", waterFull);
}

void printInfo() {
  readSensors();
  Serial.printf("Pakan: %.1f g (raw %ld, offset %ld, scale %.3f, valid %d)\n", last.foodG, last.foodRaw, hxOffset, hxScale, last.foodValid);
  Serial.printf("Air: %.1f%% (ADC %d, kering %d, penuh %d, valid %d)\n", last.waterPct, last.waterRaw, waterDry, waterFull, last.waterValid);
  Serial.printf("PIR: %d | WiFi: %s | MQTT: %s | IP: %s\n", digitalRead(PIN_PIR), WiFi.status() == WL_CONNECTED ? "ok" : "putus",
                mqtt.connected() ? "ok" : "putus", WiFi.localIP().toString().c_str());
}

void handleSerial() {
  if (!Serial.available()) return;
  String line = Serial.readStringUntil('\n'); line.trim();
  if (phase != IDLE) { Serial.println("Aktuator sedang berjalan, coba lagi."); return; }
  if (line == "tare") { hxOffset = scale.read_average(20); saveCalibration(); Serial.println("Tare tersimpan (wadah pakan kosong)."); }
  else if (line.startsWith("cal ")) {
    float grams = line.substring(4).toFloat();
    if (grams <= 0) { Serial.println("Format: cal <berat beban acuan dalam gram>"); return; }
    hxScale = (scale.read_average(20) - hxOffset) / grams; saveCalibration();
    Serial.printf("Scale tersimpan: %.3f raw/gram\n", hxScale);
  }
  else if (line == "wkering") { readSensors(); waterDry = last.waterRaw; saveCalibration(); Serial.printf("ADC kering = %d\n", waterDry); }
  else if (line == "wpenuh") { readSensors(); waterFull = last.waterRaw; saveCalibration(); Serial.printf("ADC penuh = %d\n", waterFull); }
  else if (line == "info") printInfo();
  else Serial.println("Perintah: info | tare | cal <gram> | wkering | wpenuh");
}

// ---------- setup / loop ----------
void setup() {
  Serial.begin(115200);
  pinMode(PIN_PIR, INPUT);
  pinMode(PIN_PUMP, OUTPUT);
  digitalWrite(PIN_PUMP, LOW);
  analogReadResolution(12);
  analogSetPinAttenuation(PIN_WATER, ADC_11db);

  servo.setPeriodHertz(50);
  servo.attach(PIN_SERVO, 500, 2400);
  servo.write(SERVO_CLOSED_DEG);

  scale.begin(PIN_HX711_DT, PIN_HX711_SCK);

  prefs.begin("petstation", false);
  hxOffset = prefs.getLong("hx_off", 0);
  hxScale = prefs.getFloat("hx_scale", hxScale);
  waterDry = prefs.getInt("w_dry", waterDry);
  waterFull = prefs.getInt("w_full", waterFull);
  interruptedId = prefs.getString("running", "");
  prefs.remove("running");

  WiFi.mode(WIFI_STA);
  WiFi.begin(WIFI_SSID, WIFI_PASSWORD);
  configTime(0, 0, "pool.ntp.org", "time.google.com");

#if MQTT_TLS
  net.setCACert(MQTT_CA_CERT);   // sertifikat broker dan nama host diverifikasi
  net.setHandshakeTimeout(10);
#endif
  mqtt.setServer(MQTT_HOST, MQTT_PORT);
  mqtt.setBufferSize(1536);
  mqtt.setKeepAlive(15);
  mqtt.setSocketTimeout(MQTT_TLS ? 10 : 3);
  mqtt.setCallback(onMessage);

  Serial.println("Petstation ESP32 siap. Ketik 'info' untuk status, atau tare/cal/wkering/wpenuh untuk kalibrasi.");
}

void loop() {
  tickExecution();                       // prioritas: hentikan aktuator tepat waktu
  if (digitalRead(PIN_PIR) == HIGH) pirLatched = true;
  handleSerial();
  ensureNetwork();
  if (mqtt.connected()) {
    mqtt.loop();
    if (phase == IDLE && millis() - lastTelemetry >= TELEMETRY_MS) { lastTelemetry = millis(); publishTelemetry(); }
    if (pendingPayload.length() && millis() - pendingSentAt >= RESEND_MS) { mqtt.publish(pendingTopic.c_str(), pendingPayload.c_str()); pendingSentAt = millis(); }
  }
}
