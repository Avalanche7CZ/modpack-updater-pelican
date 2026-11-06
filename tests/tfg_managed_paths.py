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

    def test_verified_unknown_roots_complete_without_live_approval(self):
        result = self.prepare()
        self.assertEqual(result['state'], 'completed', result)
        self.assertEqual(result['files_verified'], 1)
        self.assertTrue((self.stage / ('a' * 32) / 'download-0').is_file())
        manifest = self.worker.handle(self.server, 'manifest', {'operation_id': 'a' * 32})
        review = [row['path'] for row in manifest['directory_discovery'] if row['classification'] == 'review']
        self.assertEqual(review, ['DiscordIntegration-Data', 'tacz'])
        self.put('tacz/local.json', b'local')
        self.assertNotIn('tacz/local.json', self.worker.inventory(self.server)['files'])
        scope = {'manage': ['tacz'], 'exclude': []}
        inventory = self.worker.handle(self.server, 'inventory', {'directory_scope': scope})
        self.assertIn('tacz/local.json', inventory['files'])
        self.assertNotIn('tacz/local.json', self.worker.inventory(self.server)['files'])
        self.assertNotIn('tacz/local.json', self.worker.handle(self.server, 'inventory', {'directory_scope': {'manage': [], 'exclude': ['tacz']}})['files'])

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
        self.assertEqual(result['state'], 'completed')
        manifest = self.worker.handle(self.server, 'manifest', {'operation_id': 'b' * 32})
        self.assertIn('unreviewed/private.cfg', manifest['files'])

    def test_download_recipe_cannot_approve_its_own_directories(self):
        recipe = self.recipe(self.archive(self.pack()))
        recipe['additional_managed_directories'] = ['DiscordIntegration-Data', 'tacz']
        self.worker.prepare(self.server, recipe, 'c' * 32)
        self.worker.threads[(self.server, 'c' * 32)].join(10)
        self.assertEqual(self.worker.status(self.server, 'c' * 32)['state'], 'completed')
        self.put('tacz/private.cfg', b'local')
        self.assertNotIn('tacz/private.cfg', self.worker.inventory(self.server)['files'])

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
            with self.assertRaises(w.PreparationFailure) as failure:
                self.worker.inventory(self.server)
            self.assertEqual(failure.exception.code, 'PERMISSION_DENIED')
        finally:
            directory.chmod(0o700)

    def test_gtnh_ftb_wrappers_and_nested_runtime(self):
        for index, wrapper in enumerate(['GT_New_Horizons_Server_2.8.1', 'FTB-Evolution-Server']):
            pack = [(wrapper + '/' + name, value) for name, value in [
                ('mods/example.jar', b'jar'), ('config/pack.cfg', b'config'),
                ('customcontent/assets/example.json', b'{}'), ('customcontent/backups/b.zip', b'private'),
                ('config/secrets/private.key', b'private'), ('config/saves/session.lock', b'private'),
                ('world/region/r.0.0.mca', b'world'), ('scripts/playerdata/user.dat', b'player'),
                ('libraries/bootstrap.jar', b'jar')]]
            op = str(index + 1) * 32
            self.assertEqual(self.prepare(op, pack)['state'], 'completed')
            manifest = self.worker.handle(self.server, 'manifest', {'operation_id': op})
            self.assertEqual(set(manifest['files']), {'mods/example.jar', 'config/pack.cfg',
                'customcontent/assets/example.json', 'libraries/bootstrap.jar'})
            self.assertIn({'path': 'customcontent', 'classification': 'review', 'default': 'review'}, manifest['directory_discovery'])

    def test_unknown_safe_names_need_no_ascii_name_allowlist(self):
        name = 'Custom Content'
        self.assertEqual(self.prepare(entries=[(name + '/assets/item.json', b'{}')])['state'], 'completed')
        self.put(name + '/assets/local.json', b'{}')
        result = self.worker.handle(self.server, 'inventory', {'directory_scope': {'manage': [name], 'exclude': []}})
        self.assertIn(name + '/assets/local.json', result['files'])
        self.put('kubejs/data/pack/recipes/item.json', b'{}')
        self.assertIn('kubejs/data/pack/recipes/item.json', self.worker.inventory(self.server)['files'])
        self.put('config/.private/credentials.txt', b'secret')
        self.assertNotIn('config/.private/credentials.txt', self.worker.inventory(self.server)['files'])

    def test_retained_archive_reused_and_reverified(self):
        self.assertEqual(self.prepare()['state'], 'completed')
        recipe = self.recipe(self.root / 'pack.zip')
        # Removing the source proves reuse of retained bytes, including local artifact recipes.
        (self.root / 'pack.zip').unlink()
        with patch.object(w, 'fetch', side_effect=AssertionError('Unexpected network download')):
            self.worker.prepare(self.server, recipe, 'd' * 32)
            self.worker.threads[(self.server, 'd' * 32)].join(10)
        self.assertEqual(self.worker.status(self.server, 'd' * 32)['state'], 'completed')
        (self.stage / ('a' * 32) / 'download-0').write_bytes(b'corrupted')
        (self.stage / ('d' * 32) / 'download-0').write_bytes(b'corrupted')
        self.worker.prepare(self.server, recipe, 'e' * 32)
        self.worker.threads[(self.server, 'e' * 32)].join(10)
        self.assertEqual(self.worker.status(self.server, 'e' * 32)['diagnostic']['code'], 'CHECKSUM_MISMATCH')

    def test_scope_diagnostic_never_discloses_raw_urls_or_host_paths(self):
        error = w.ManagedPathFailure(['https://user:password@host/private?token=secret', '/etc/private', 'secret-token-directory/value'])
        text = str(error)
        for forbidden in ['https', 'password', 'host', '/etc', 'secret-token-directory']:
            self.assertNotIn(forbidden, text)


if __name__ == '__main__':
    unittest.main()
