"""Focused safe diagnostics, durable telemetry and archive-root regressions."""
import json
import socket
import ssl
import subprocess
import sys
import unittest
from pathlib import Path
from unittest.mock import patch

import local_worker as fixtures
w = fixtures.fixtures.w


class TelemetryTests(unittest.TestCase):
    setUp = fixtures.LocalTests.setUp
    put = fixtures.LocalTests.put
    archive = fixtures.LocalTests.archive
    recipe = fixtures.LocalTests.recipe
    request = fixtures.LocalTests.request

    def test_diagnostics_are_structured_and_never_echo_exceptions(self):
        cases = [(socket.gaierror('https://private?token=secret'), 'NETWORK_DNS'),
                 (TimeoutError('/private/root'), 'NETWORK_TIMEOUT'),
                 (ssl.SSLError('password'), 'NETWORK_TLS'),
                 (PermissionError('/private/root'), 'PERMISSION_DENIED'),
                 (w.zipfile.BadZipFile('private'), 'ARCHIVE_INVALID'),
                 (w.PreparationFailure('HTTP_STATUS', http_status=403), 'HTTP_STATUS'),
                 (w.PreparationFailure('CHECKSUM_MISSING'), 'CHECKSUM_MISSING')]
        for error, code in cases:
            detail = w.diagnostic(error, 'downloading', {'file': '/private/root?token=secret'})
            self.assertEqual(detail['code'], code)
            self.assertEqual(detail['phase'], 'downloading')
            text = json.dumps(detail)
            for private in ['https:', '/private', 'password', 'token=']:
                self.assertNotIn(private, text)
        self.assertNotIn('file', w.safe_file('config/password.conf'))
        self.assertEqual(w.safe_file('config/ordinary.cfg')['file'], 'config/ordinary.cfg')

    def test_actual_speed_unknown_total_throttling_and_durable_failure(self):
        clock, frames = [0.0], []
        path = self.stage / 'checkpoint.json'
        report = w.ProgressState(path, {'operation_id': 'a' * 32}, frames.append, lambda: clock[0])
        report(state='downloading', bytes_done=0, bytes_total=400)
        clock[0] = 1
        report(bytes_done=100)
        self.assertEqual(len(frames), 1)
        self.assertEqual(report.data['progress']['speed_bytes_per_second'], 100)
        self.assertEqual(report.data['progress']['eta_seconds'], 3)
        clock[0] = 2
        report(bytes_done=200, bytes_total=None)
        self.assertIsNone(frames[-1]['progress']['eta_seconds'])
        for n in range(201, 300):
            report(bytes_done=n)
        self.assertEqual(len(frames), 2, 'Per-chunk writes were not throttled')
        report.failed(w.PreparationFailure('CHECKSUM_MISMATCH', artifact_index=1))
        saved = json.loads(path.read_text())
        self.assertEqual(saved['state'], 'failed')
        self.assertEqual(saved['progress']['bytes_done'], 299)
        self.assertEqual(saved['diagnostic']['code'], 'CHECKSUM_MISMATCH')
        self.assertEqual(saved['diagnostic']['context']['artifact_index'], 1)

    def test_stdio_frames_and_comparison_preserve_preparation_and_live_files(self):
        self.put('config/example.cfg', b'custom')
        self.put('world/level.dat', b'untouched')
        request = self.request('prepare', operation_id='c' * 32,
            recipe=self.recipe(self.archive([('config/example.cfg', b'upstream')])))
        request['progress_stream'] = True
        process = subprocess.run([sys.executable, str(Path(fixtures.local.__file__))],
            input=json.dumps(request), text=True, capture_output=True, check=True, timeout=10)
        frames = [json.loads(line) for line in process.stdout.splitlines()]
        self.assertEqual(frames[-1]['type'], 'result')
        self.assertEqual(frames[-1]['data']['state'], 'completed')
        stages = frames[-1]['data']['stages']
        self.assertTrue({'downloading', 'verifying', 'extracting', 'hashing'} <= stages.keys())
        self.assertEqual(stages['downloading']['bytes_done'], (self.root / 'pack.zip').stat().st_size)
        self.assertEqual(stages['hashing']['files_done'], 1)
        evidence = self.stage / ('c' * 32) / 'operation.json'
        before = evidence.read_bytes()
        result = fixtures.local.run(self.request('inventory', operation_id='c' * 32))
        self.assertNotIn('world/level.dat', result['files'])
        self.assertEqual(evidence.read_bytes(), before)
        comparison = json.loads((evidence.parent / 'comparison.json').read_text())
        self.assertEqual(comparison['progress']['files_done'], 1)
        self.assertEqual((self.live / 'config/example.cfg').read_bytes(), b'custom')
        (evidence.parent / 'cancel').touch()
        with self.assertRaises(InterruptedError):
            fixtures.local.run(self.request('inventory', operation_id='c' * 32))
        self.assertEqual(json.loads((evidence.parent / 'comparison.json').read_text())['state'], 'cancelled')

    def test_unapproved_sole_directory_is_not_silently_stripped(self):
        for index, root in enumerate(['tacz', 'UnknownPackRoot']):
            result = fixtures.local.run(self.request('prepare', operation_id=str(index) * 32,
                recipe=self.recipe(self.archive([(root + '/assets/example.json', b'{}')]))))
            self.assertEqual(result['state'], 'completed')
            self.assertTrue((self.stage / (str(index) * 32) / ('files/' + root + '/assets/example.json')).is_file())
        self.settings['servers'][self.server]['archive_roots'] = ['ReviewedPackRoot']
        result = fixtures.local.run(self.request('prepare', operation_id='e' * 32,
            recipe=self.recipe(self.archive([('ReviewedPackRoot/config/example.cfg', b'upstream')]))))
        self.assertEqual(result['state'], 'completed')
        self.assertTrue((self.stage / ('e' * 32) / 'files/config/example.cfg').is_file())
        result = fixtures.local.run(self.request('prepare', operation_id='f' * 32,
            recipe=self.recipe(self.archive([('ReviewedPackRoot/tacz/assets/example.json', b'{}')]))))
        self.assertEqual(result['state'], 'completed')
        self.assertNotIn('tacz', w.MANAGED_DIRS)


if __name__ == '__main__':
    unittest.main(defaultTest='TelemetryTests')
