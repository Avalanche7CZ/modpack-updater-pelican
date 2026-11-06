import unittest
from pathlib import Path
from unittest.mock import patch

import node_worker as fixtures

w = fixtures.w


class TfgManagedPaths(unittest.TestCase):
    setUp = fixtures.WorkerTests.setUp
    archive = fixtures.WorkerTests.archive
    recipe = fixtures.WorkerTests.recipe
    put = fixtures.WorkerTests.put

    def pack(self):
        files = [(name + '/fixture.txt', name.encode()) for name in
            ['config', 'defaultconfigs', 'DiscordIntegration-Data', 'kubejs', 'mods', 'tacz']]
        files += [(name, b'upstream') for name in ['forge-auto-install.txt', 'minecraft_server.jar', 'README.md',
            'server-icon.png', 'server.properties', 'server_starter.conf', 'start_server.bat', 'start_server.sh']]
        return files

    def prepare(self, operation='a' * 32, entries=None):
        recipe = self.recipe(self.archive(self.pack() if entries is None else entries))
        self.worker.prepare(self.server, recipe, operation)
        self.worker.threads[(self.server, operation)].join(10)
        return self.worker.status(self.server, operation)

    def test_reproduces_official_layout_failure_after_download_and_extraction(self):
        result = self.prepare()
        self.assertEqual(result['state'], 'failed')
        self.assertEqual(result['files_verified'], 1)
        self.assertTrue((self.stage / ('a' * 32) / 'download-0').is_file())
        self.assertTrue((self.stage / ('a' * 32) / 'files/tacz/fixture.txt').is_file())
        self.assertFalse((self.stage / ('a' * 32) / 'manifest.json').exists())
        self.assertEqual(result['diagnostic']['code'], 'UNAPPROVED_MANAGED_DIRECTORY')
        self.assertEqual(result['diagnostic']['directories'], ['DiscordIntegration-Data', 'tacz'])

    def test_explicit_additions_complete_manifest_and_inventory_without_live_writes(self):
        self.settings['servers'][self.server]['additional_managed_directories'] = ['DiscordIntegration-Data', 'tacz']
        original = self.put('config/local.cfg', b'local customization')
        self.put('world/level.dat', b'world')
        self.put('tacz/local.json', b'local content')
        result = self.prepare()
        self.assertEqual(result['state'], 'completed', result)
        manifest = self.worker.handle(self.server, 'manifest', {'operation_id': 'a' * 32})
        self.assertEqual(set(manifest['files']), set(path for path, _ in self.pack()))
        inventory = self.worker.inventory(self.server)
        self.assertIn('tacz/local.json', inventory['files'])
        self.assertNotIn('world/level.dat', inventory['files'])
        self.assertEqual(original.read_bytes(), b'local customization')
        self.assertFalse((self.live / 'tacz/fixture.txt').exists())
        self.assertNotIn('tacz', w.MANAGED_DIRS)
        self.assertNotIn('DiscordIntegration-Data', w.MANAGED_DIRS)

    def test_no_automatic_approval_and_single_approved_root_is_not_stripped(self):
        self.settings['servers'][self.server]['additional_managed_directories'] = ['tacz']
        result = self.prepare(entries=[('tacz/guns.json', b'approved')])
        self.assertEqual(result['state'], 'completed', result)
        self.assertIn('tacz/guns.json', self.worker.handle(self.server, 'manifest', {'operation_id': 'a' * 32})['files'])
        result = self.prepare('b' * 32, self.pack() + [('unreviewed/private.cfg', b'not approved')])
        self.assertEqual(result['state'], 'failed')
        self.assertIn('unreviewed', result['diagnostic']['directories'])

    def test_download_recipe_cannot_approve_its_own_directories(self):
        recipe = self.recipe(self.archive(self.pack()))
        recipe['additional_managed_directories'] = ['DiscordIntegration-Data', 'tacz']
        self.worker.prepare(self.server, recipe, 'c' * 32)
        self.worker.threads[(self.server, 'c' * 32)].join(10)
        self.assertEqual(self.worker.status(self.server, 'c' * 32)['state'], 'failed')

    def test_runtime_world_and_nested_backup_exclusions_cannot_be_overridden(self):
        for name in ['world', 'playerdata', 'logs', 'backups', 'data', 'DIM-1', '../x', '/private', '.hidden', 'a/b']:
            with self.subTest(name=name), self.assertRaises(ValueError):
                w.additional_directories([name])
        self.settings['servers'][self.server]['additional_managed_directories'] = ['tacz']
        self.put('tacz/backups/private.zip', b'backup')
        self.assertNotIn('tacz/backups/private.zip', self.worker.inventory(self.server)['files'])
        self.put('server.properties', b'level-name=tacz\n')
        self.put('tacz/level.dat', b'world')
        with self.assertRaises(ValueError):
            self.worker.hashes(self.server, ['tacz/level.dat'])
        self.assertNotIn('tacz/level.dat', self.worker.inventory(self.server)['files'])

    def test_unreadable_approved_directory_does_not_return_complete_empty_inventory(self):
        self.settings['servers'][self.server]['additional_managed_directories'] = ['tacz']
        directory = self.put('tacz/guns.json').parent
        directory.chmod(0)
        try:
            with self.assertRaises(PermissionError):
                self.worker.inventory(self.server)
        finally:
            directory.chmod(0o700)

    def test_scope_diagnostic_never_discloses_raw_urls_or_host_paths(self):
        error = w.ManagedPathFailure(['https://user:password@host/private?token=secret', '/etc/private', 'secret-token-directory/value'])
        text = str(error)
        for forbidden in ['https', 'password', 'host', '/etc', 'secret-token-directory']:
            self.assertNotIn(forbidden, text)


if __name__ == '__main__':
    unittest.main()
