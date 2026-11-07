import tempfile
import unittest
import uuid
from datetime import datetime, timedelta, timezone
from unittest.mock import Mock, patch

from station import Station


class SimulatorTests(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory()
        self.client_patch = patch('station.mqtt.Client')
        self.client_patch.start()
        self.sleep_patch = patch('station.time.sleep')
        self.sleep_patch.start()
        self.station = Station('test-station', 'test', 'test', state_dir=self.directory.name)
        self.station.send = Mock()

    def tearDown(self):
        self.client_patch.stop()
        self.sleep_patch.stop()
        self.directory.cleanup()

    def command(self, action='feed', **parameters):
        return {'schema_version': 1, 'device_id': 'test-station', 'command_id': str(uuid.uuid4()), 'action': action, 'parameters': {'duration_ms': 2000, **parameters}, 'expires_at': (datetime.now(timezone.utc) + timedelta(seconds=15)).isoformat()}

    def test_duplicate_command_and_restart_never_dispense_twice(self):
        command = self.command()
        self.station.execute(command)
        self.assertEqual(815, self.station.data['food_g'])
        self.station.execute(command)
        self.assertEqual(815, self.station.data['food_g'])
        restarted = Station('test-station', 'test', 'test', state_dir=self.directory.name)
        restarted.send = Mock()
        restarted.execute(command)
        self.assertEqual(815, restarted.data['food_g'])
        self.assertFalse(restarted.busy)

    def test_expired_command_is_rejected_without_changing_reservoir(self):
        command = self.command()
        command['expires_at'] = (datetime.now(timezone.utc) - timedelta(seconds=1)).isoformat()
        self.station.execute(command)
        self.assertEqual('rejected', self.station.data['processed'][command['command_id']]['status'])
        self.assertEqual(840, self.station.data['food_g'])

    def test_local_guards_reject_low_water_invalid_sensor_and_duration(self):
        self.station.data['water_pct'] = 8
        command = self.command('water')
        self.station.execute(command)
        self.assertEqual('rejected', self.station.data['processed'][command['command_id']]['status'])
        self.station.data['scenario'] = 'invalid_sensor'
        command = self.command()
        self.station.execute(command)
        self.assertEqual('rejected', self.station.data['processed'][command['command_id']]['status'])
        self.station.data['scenario'] = 'normal'
        command = self.command(duration_ms=5000)
        self.station.execute(command)
        self.assertEqual('rejected', self.station.data['processed'][command['command_id']]['status'])
        self.assertEqual(840, self.station.data['food_g'])

    def test_cooldown_blocks_a_new_command_and_outputs_return_to_idle(self):
        self.station.execute(self.command())
        command = self.command()
        self.station.execute(command)
        self.assertEqual('rejected', self.station.data['processed'][command['command_id']]['status'])
        self.assertEqual(815, self.station.data['food_g'])
        self.assertEqual('closed', self.station.sensors()['servo'])
        self.assertEqual('off', self.station.sensors()['pump'])

    def test_missing_result_is_recovered_without_reexecuting_actuator(self):
        self.station.data['scenario'] = 'no_result'
        command = self.command()
        self.station.execute(command)
        self.assertEqual(815, self.station.data['food_g'])
        self.assertFalse(self.station.busy)
        self.assertEqual('completed', self.station.data['processed'][command['command_id']]['status'])
        self.station.send.reset_mock()
        self.station.execute(self.command('scenario', scenario='normal'))
        results = [call.args[1] for call in self.station.send.call_args_list if call.args[0] == 'result']
        self.assertTrue(any(result['command_id'] == command['command_id'] and result['status'] == 'completed' for result in results))


if __name__ == '__main__':
    unittest.main()
