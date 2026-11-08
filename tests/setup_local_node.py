import importlib.util
import os
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest.mock import patch
from types import SimpleNamespace

SPEC = importlib.util.spec_from_file_location('setup_node', Path(__file__).parents[1] / 'node-worker/setup-local-node.py')
setup = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(setup)


class SetupTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name)
        self.uid = 65534 if os.geteuid() != 65534 else 65533

    def acl(self, path):
        return subprocess.check_output(['/usr/bin/getfacl', '-c', '-n', '-p', str(path)], text=True)

    def test_approval_rejects_remote_metadata_traversal_and_overlap(self):
        root, stage = self.root / 'volumes', self.root / 'staging'
        root.mkdir(); stage.mkdir()
        snapshot = {'node_id': 2, 'node_uuid': 'a' * 8 + '-aaaa-4aaa-8aaa-' + 'a' * 12,
            'volume_root': str(root), 'queue_driver': 'redis', 'servers': [], 'active_operations': 0}
        self.assertEqual(setup.approve(snapshot, 2, root, stage), [])
        for changed in [{'node_id': 3}, {'volume_root': '/'}, {'queue_driver': 'sync'},
                        {'active_operations': 1}, {'servers': ['../secret']}]:
            with self.assertRaises(ValueError):
                setup.approve({**snapshot, **changed}, 2, root, stage)
        with self.assertRaises(ValueError):
            setup.approve(snapshot, 2, root, root / 'stage')
        with self.assertRaises(ValueError):
            setup.approve({**snapshot, 'volume_roots': [str(root), str(stage.parent)]}, 2, root, stage)
        link = self.root / 'link'; link.symlink_to(root, target_is_directory=True)
        with self.assertRaises(ValueError):
            setup.canonical(str(link))
        with self.assertRaises(ValueError):
            setup.canonical(str(root / '..' / 'volumes'))
        with self.assertRaises(ValueError):
            setup.reject_nested_mounts(root, '1 2 0:1 / %s/sub rw - ext4 /dev/test rw' % root)

    def test_acl_preserves_other_effective_permissions_and_is_inherited(self):
        volume = self.root / 'volume'; volume.mkdir(mode=0o700)
        subprocess.run(['/usr/bin/setfacl', '-m', 'u:65532:rwx,m::r--', str(volume)], check=True)
        fd = os.open(volume, os.O_RDONLY | os.O_DIRECTORY)
        try:
            setup.grant(fd, self.uid, True)
            setup.grant(fd, self.uid, True)  # idempotent
        finally:
            os.close(fd)
        acl = self.acl(volume)
        self.assertIn('user:65532:r--', acl)
        self.assertIn('user:%s:r-x' % self.uid, acl)
        self.assertIn('group::---', acl)
        self.assertIn('other::---', acl)
        self.assertIn('default:user:%s:r-x' % self.uid, acl)
        future = volume / 'future'; future.mkdir(mode=0o750)
        self.assertIn('user:%s:r-x' % self.uid, self.acl(future))

    def test_existing_scope_skips_worlds_unknown_dirs_links_and_special_files(self):
        volume = self.root / 'volume'; volume.mkdir(mode=0o700)
        for directory in ['mods', 'config', 'world', 'private', 'saves']:
            (volume / directory).mkdir(mode=0o700)
            (volume / directory / 'fixture.bin').write_bytes(b'preserve')
        (volume / 'server.properties').write_text('level-name=config\n')
        outside = self.root / 'outside'; outside.write_bytes(b'secret')
        (volume / 'mods/link').symlink_to(outside)
        os.mkfifo(volume / 'mods/fifo')
        fd = os.open(volume, os.O_RDONLY | os.O_DIRECTORY)
        try:
            setup.provision_volume(fd, self.uid)
        finally:
            os.close(fd)
        self.assertIn('user:%s:r--' % self.uid, self.acl(volume / 'mods/fixture.bin'))
        for path in [volume / 'world/fixture.bin', volume / 'config/fixture.bin', volume / 'private/fixture.bin', outside]:
            self.assertNotIn('user:%s:' % self.uid, self.acl(path))
            self.assertIn(path.read_bytes(), [b'preserve', b'secret'])

    def test_deployment_grants_directory_writes_and_inherits_both_owners_without_world_access(self):
        volume = self.root / 'volume'; volume.mkdir(mode=0o700)
        for name in ['mods', 'config', 'world', 'private']:
            (volume / name).mkdir(mode=0o700)
            (volume / name / 'file').write_text('unchanged')
        (volume / 'mods/link').symlink_to(volume / 'world', target_is_directory=True)
        fd = os.open(volume, os.O_RDONLY | os.O_DIRECTORY)
        try:
            setup.provision_volume(fd, self.uid, deployment=True)
        finally:
            os.close(fd)
        acl = self.acl(volume / 'mods')
        self.assertIn('user:%s:rwx' % self.uid, acl)
        self.assertIn('default:user:%s:rwx' % os.getuid(), acl)
        for name in ['world', 'private']:
            self.assertNotIn('user:%s:' % self.uid, self.acl(volume / name))
        self.assertEqual((volume / 'world/file').read_text(), 'unchanged')
        future = volume / 'mods/future'; future.mkdir()
        self.assertIn('user:%s:rwx' % self.uid, self.acl(future))
        self.assertIn('default:user:%s:rwx' % os.getuid(), self.acl(future))

    def test_unprivileged_setup_refused(self):
        result = subprocess.run(['/usr/bin/python3', str(Path(setup.__file__)), '--node', '1',
            '--volume-root', str(self.root)], capture_output=True, text=True)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('one-time host setup as root', result.stderr)

    def test_hard_links_cannot_grant_read_access_outside_the_approved_volume(self):
        volume = self.root / 'volume'; volume.mkdir(mode=0o700)
        outside = self.root / 'private'; outside.write_bytes(b'private')
        os.link(outside, volume / 'linked.cfg')
        fd = os.open(volume, os.O_RDONLY | os.O_DIRECTORY)
        try:
            with self.assertRaises(ValueError):
                setup.provision_volume(fd, self.uid)
        finally:
            os.close(fd)
        self.assertNotIn('user:%s:' % self.uid, self.acl(outside))

    def test_panel_code_is_executed_only_after_dropping_privileges(self):
        account = SimpleNamespace(pw_name='www-data', pw_dir='/var/www')
        with patch.object(setup.subprocess, 'run', return_value=SimpleNamespace(stdout='{}')) as run:
            setup.run_panel(Path('/var/www/pelican'), Path('/usr/bin/php'), account, ['queue:restart'])
        command = run.call_args.args[0]
        self.assertEqual(command[:6], ['/usr/sbin/runuser', '-u', 'www-data', '--', '/usr/bin/php', '/var/www/pelican/artisan'])
        self.assertNotIn('shell', run.call_args.kwargs)

    def test_additional_directories_require_explicit_review_and_keep_runtime_exclusions(self):
        self.assertNotIn('tacz', setup.PACK_DIRS)
        for name in ['world', 'backups', 'logs', 'playerdata', 'DIM1', 'data', '../private', 'a/b']:
            with self.subTest(name=name), self.assertRaises(ValueError):
                setup.approved_additions([name])
        volume = self.root / 'volume'; volume.mkdir(mode=0o700)
        (volume / 'tacz/backups').mkdir(parents=True, mode=0o700)
        (volume / 'tacz/guns.json').write_bytes(b'pack data')
        (volume / 'tacz/backups/runtime.zip').write_bytes(b'backup')
        fd = os.open(volume, os.O_RDONLY | os.O_DIRECTORY)
        try:
            setup.provision_volume(fd, self.uid)
            self.assertNotIn('user:%s:' % self.uid, self.acl(volume / 'tacz/guns.json'))
            setup.provision_volume(fd, self.uid, ['tacz'])
        finally:
            os.close(fd)
        self.assertIn('user:%s:r--' % self.uid, self.acl(volume / 'tacz/guns.json'))
        self.assertNotIn('user:%s:' % self.uid, self.acl(volume / 'tacz/backups/runtime.zip'))


if __name__ == '__main__':
    unittest.main()
