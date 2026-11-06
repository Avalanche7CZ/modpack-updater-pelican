import fcntl
import json
from pathlib import Path
import subprocess
import sys
import unittest
from unittest.mock import patch

import node_worker as fixtures

sys.path.insert(0, str(Path(__file__).parents[1] / 'node-worker'))
import local


class LocalTests(unittest.TestCase):
    setUp = fixtures.WorkerTests.setUp
    put = fixtures.WorkerTests.put
    archive = fixtures.WorkerTests.archive
    recipe = fixtures.WorkerTests.recipe

    def test_node_probe_is_read_only_and_uuid_staging_is_private(self):
        volumes, stages = self.root / 'volumes', self.root / 'stages'
        volumes.mkdir(mode=0o700); stages.mkdir(mode=0o700)
        live = volumes / self.server; live.mkdir(mode=0o700)
        stage = stages / self.server
        node = {'uid': local.os.geteuid(), 'volume_root': str(volumes), 'staging_parent': str(stages)}
        self.assertTrue(local.run({'operation': 'node_capabilities', 'node': node})['configuration_ready'])
        self.assertEqual(list(stages.iterdir()), [])
        request = {'operation': 'provision', 'server': self.server,
            'servers': {self.server: {'root': str(live), 'staging': str(stage)}}}
        self.assertTrue(local.run(request)['configuration_ready'])
        self.assertEqual(stage.stat().st_mode & 0o777, 0o700)
        self.assertEqual(stage.stat().st_uid, local.os.geteuid())
        self.assertTrue(local.run(request)['configuration_ready'])
        stage.rmdir(); stage.symlink_to(self.stage, target_is_directory=True)
        with self.assertRaises(local.LocalError):
            local.run(request)
        with self.assertRaises(local.LocalError):
            local.run({'operation': 'node_capabilities', 'node': {**node, 'uid': 0}})

    def request(self, operation, **payload):
        # Local mode deliberately needs no server token.
        self.settings['servers'][self.server].pop('token', None)
        return {'servers': self.settings['servers'], 'server': self.server,
                'operation': operation, 'payload': payload}

    def test_real_stdio_preparation_preserves_live_files_and_worlds(self):
        self.put('config/custom.cfg', b'local customization')
        world = self.put('world/level.dat', b'world')
        archive = self.archive([('config/custom.cfg', b'upstream'), ('mods/example.jar', b'fixture')])
        recipe = self.recipe(archive)
        request = self.request('prepare', recipe=recipe, operation_id='a' * 32)
        result = subprocess.run([sys.executable, str(Path(local.__file__))], input=json.dumps(request),
                                capture_output=True, text=True, timeout=10, check=True)
        self.assertEqual(json.loads(result.stdout)['state'], 'completed')
        self.assertEqual(local.run(request)['state'], 'completed')
        manifest = local.run(self.request('manifest', operation_id='a' * 32))
        self.assertEqual(manifest['verification'], 'upstream-checksums')
        self.assertEqual((self.live / 'config/custom.cfg').read_bytes(), b'local customization')
        self.assertEqual(world.read_bytes(), b'world')
        with patch.object(local.Worker, 'hashes', side_effect=AssertionError('test scanned files')):
            self.assertFalse(local.run(self.request('capabilities'))['deploy'])
        inventory = local.run(self.request('inventory'))
        self.assertNotIn('world/level.dat', inventory['files'])
        self.assertTrue(inventory['complete'])

    def test_missing_and_unsafe_paths(self):
        for bad in [str(self.root / 'missing'), str(self.live / '..' / 'live'), '/']:
            self.settings['servers'][self.server]['root'] = bad
            with self.assertRaises(local.LocalError):
                local.run(self.request('capabilities'))
        self.settings['servers'][self.server]['root'] = str(self.live)
        link = self.root / 'link'
        link.symlink_to(self.live, target_is_directory=True)
        self.settings['servers'][self.server]['root'] = str(link)
        with self.assertRaises(local.LocalError):
            local.run(self.request('capabilities'))

    def test_overlapping_staging_and_servers(self):
        child = self.live / 'stage'
        child.mkdir(mode=0o700)
        self.settings['servers'][self.server]['staging'] = str(child)
        with self.assertRaises(ValueError):
            local.run(self.request('capabilities'))

    def test_permission_checks_and_root_refusal(self):
        with patch.object(local.os, 'geteuid', return_value=0), self.assertRaises(local.LocalError):
            local.run(self.request('capabilities'))
        with patch.object(local.os, 'access', return_value=False), self.assertRaises(local.LocalError):
            local.run(self.request('capabilities'))
        self.live.chmod(0)
        try:
            with self.assertRaises(local.LocalError):
                local.run(self.request('capabilities'))
        finally:
            self.live.chmod(0o700)
        self.stage.chmod(0o755)
        with self.assertRaises(local.LocalError):
            local.run(self.request('capabilities'))

    def test_unsafe_archive_and_hash_fail_without_live_changes(self):
        self.put('config/a.cfg', b'local')
        for i, entries in enumerate([[('../escape', b'bad')], [('config/a.cfg', b'upstream')]]):
            recipe = self.recipe(self.archive(entries))
            if i:
                recipe['downloads'][0]['hashes']['sha256'] = '0' * 64
            result = local.run(self.request('prepare', recipe=recipe, operation_id=str(i) * 32))
            self.assertEqual(result['state'], 'failed')
            saved = json.loads((self.stage / (str(i) * 32) / 'operation.json').read_text())
            self.assertEqual(saved['diagnostic']['code'], ['ARCHIVE_UNSAFE', 'CHECKSUM_MISMATCH'][i])
            before = (self.stage / (str(i) * 32) / 'operation.json').read_bytes()
            local.public_result(saved)
            self.assertEqual(before, (self.stage / (str(i) * 32) / 'operation.json').read_bytes())
        self.assertEqual((self.live / 'config/a.cfg').read_bytes(), b'local')
        self.assertFalse((self.root / 'escape').exists())
        with self.assertRaises(ValueError):
            local.run(self.request('hashes', paths=['world/level.dat']))
        with self.assertRaises(ValueError):
            local.run(self.request('status', operation_id='../escape'))

    def test_scope_diagnostic_is_reconstructed_and_unknown_diagnostic_cannot_leak(self):
        unsafe = {'state': 'failed', 'error': 'https://private?token=secret',
            'diagnostic': {'schema': 1, 'code': 'UNAPPROVED_MANAGED_DIRECTORY', 'directories': ['tacz'],
                'description': 'Bearer secret', 'action': '/etc/private'}}
        text = json.dumps(local.public_result(unsafe))
        self.assertIn('tacz', text)
        self.assertNotIn('Bearer', text)
        self.assertNotIn('/etc', text)
        unsafe['diagnostic']['code'] = 'UNKNOWN'
        text = json.dumps(local.public_result(unsafe))
        self.assertNotIn('https', text)
        self.assertNotIn('secret', text)

    def test_cross_process_lock_and_interrupted_recovery(self):
        recipe = self.recipe(self.archive([('config/a.cfg', b'upstream')]))
        request = self.request('prepare', recipe=recipe, operation_id='b' * 32)
        with (self.stage / '.local-preparation.lock').open('w') as lock:
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
            with self.assertRaises(local.LocalError):
                local.run(request)
        base = self.stage / ('b' * 32)
        base.mkdir()
        fixtures.w.atomic_json(base / 'operation.json', {'state': 'downloading', 'operation_id': 'b' * 32,
            'recipe_sha256': fixtures.w.hashlib.sha256(json.dumps(recipe, sort_keys=True).encode()).hexdigest()})
        self.assertEqual(local.run(self.request('capabilities'))['configuration_ready'], True)
        self.assertEqual(json.loads((base / 'operation.json').read_text())['state'], 'downloading')
        self.assertEqual(local.run(request)['state'], 'interrupted')
        self.assertFalse((base / 'files').exists())


if __name__ == '__main__':
    unittest.main()
