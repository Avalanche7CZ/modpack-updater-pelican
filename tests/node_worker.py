import importlib.util
import io
import json
import os
from pathlib import Path
import tempfile
import threading
import unittest
from unittest.mock import patch
import uuid
import zipfile
import sys

SPEC = importlib.util.spec_from_file_location('arcturus_worker', Path(__file__).parents[1] / 'node-worker' / 'worker.py')
w = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(w)


class WorkerTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.live = self.root / 'live'
        self.stage = self.root / 'stage'
        self.live.mkdir()
        self.stage.mkdir(mode=0o700)
        self.server = str(uuid.uuid4())
        self.settings = {'servers': {self.server: {'root': str(self.live), 'staging': str(self.stage), 'token': 'a' * 64, 'allowed_hosts': ['github.com']}}}
        self.worker = w.Worker(self.settings)

    def put(self, path, text=b'mod'):
        full = self.live / path
        full.parent.mkdir(parents=True, exist_ok=True)
        full.write_bytes(text)
        return full

    def test_inventory_hashes_all_managed_files_and_extras(self):
        self.put('mods/example.jar')
        self.put('config/custom.cfg', b'custom')
        self.put('mods/manual.jar.disabled', b'off')
        self.put('world/level.dat', b'world')
        data = self.worker.inventory(self.server)
        self.assertEqual(len(data['files']), 3)
        self.assertEqual(data['files']['config/custom.cfg'], w.hashlib.sha256(b'custom').hexdigest())
        self.assertNotIn('world/level.dat', data['files'])
        self.assertTrue(data['complete'])

    def test_custom_world_is_never_hashed_even_when_named_config(self):
        self.put('server.properties', b'level-name=config\n')
        self.put('config/level.dat')
        self.assertNotIn('config/level.dat', self.worker.inventory(self.server)['files'])
        with self.assertRaises(ValueError):
            self.worker.hashes(self.server, ['config/level.dat'])

    def test_requested_missing_files_are_explicit(self):
        data = self.worker.hashes(self.server, ['mods/missing.jar'])
        self.assertEqual(data, {'files': {}, 'missing': ['mods/missing.jar']})

    def test_no_partial_hash_inventory_on_limit(self):
        self.put('mods/large.jar', b'large')
        self.settings['servers'][self.server]['max_inventory_bytes'] = 2
        with self.assertRaises(ValueError):
            self.worker.inventory(self.server)

    def test_links_are_rejected(self):
        self.put('mods/good.jar')
        (self.live / 'mods/link.jar').symlink_to('/etc/passwd')
        with self.assertRaises(OSError):
            self.worker.hashes(self.server, ['mods/link.jar'])
        with self.assertRaises(ValueError):
            self.worker.inventory(self.server)

    def test_directory_link_is_rejected(self):
        (self.live / 'mods').symlink_to(self.stage)
        with self.assertRaises(ValueError):
            self.worker.inventory(self.server)

    def test_world_hash_request_is_rejected(self):
        self.put('world/level.dat')
        with self.assertRaises(ValueError):
            self.worker.hashes(self.server, ['world/level.dat'])

    def test_traversal(self):
        for path in ('../x', '/etc/passwd', 'mods//x', 'mods/./x', 'mods\\x', 'a\x00b'):
            with self.subTest(path=path), self.assertRaises(ValueError):
                w.relative(path)

    def archive(self, entries):
        path = self.root / 'pack.zip'
        with zipfile.ZipFile(path, 'w') as out:
            for name, data in entries:
                out.writestr(name, data)
        return path

    def test_safe_archive_extracts(self):
        path = self.archive([('mods/example.jar', b'fixture'), ('run.sh', b'never executed')])
        dest = self.root / 'extracted'
        dest.mkdir()
        w.extract_zip(path, dest, 1024)
        self.assertEqual((dest / 'mods/example.jar').read_bytes(), b'fixture')

    def test_archive_traversal_is_rejected_before_writing(self):
        path = self.archive([('mods/good.jar', b'good'), ('../escape', b'bad')])
        dest = self.root / 'extracted'
        dest.mkdir()
        with self.assertRaises(ValueError):
            w.extract_zip(path, dest, 1024)
        self.assertEqual(list(dest.iterdir()), [])

    def test_archive_symlink_is_rejected(self):
        path = self.root / 'pack.zip'
        with zipfile.ZipFile(path, 'w') as out:
            info = zipfile.ZipInfo('mods/symlink')
            info.external_attr = (0o120777 << 16)
            out.writestr(info, '/etc/passwd')
        with self.assertRaises(ValueError):
            w.extract_zip(path, self.root, 1024)

    def test_archive_bomb_and_duplicate_are_rejected(self):
        path = self.archive([('mods/a.jar', b'x' * 100)])
        with self.assertRaises(ValueError):
            w.extract_zip(path, self.root / 'unused', 10)
        with self.assertWarns(UserWarning):
            path = self.archive([('mods/a.jar', b'a'), ('mods/a.jar', b'b')])
        with self.assertRaises(ValueError):
            w.extract_zip(path, self.root / 'unused', 1000)

    def test_unknown_operation_cannot_execute_shell_or_deploy(self):
        for op in ('exec', 'shell', 'deploy', 'restore', 'extract'):
            with self.subTest(op=op), self.assertRaises(ValueError):
                self.worker.handle(self.server, op, {'command': 'echo bad'})
        self.assertFalse(self.worker.handle(self.server, 'capabilities', {})['deploy'])

    def test_mod_identity_from_legacy_and_modern_metadata(self):
        mods = self.live / 'mods'
        mods.mkdir()
        for filename, meta, data in [('a.jar', 'fabric.mod.json', '{"id":"example"}'), ('b.jar.disabled', 'mcmod.info', '[{"modid":"legacy"}]')]:
            with zipfile.ZipFile(mods / filename, 'w') as jar:
                jar.writestr(meta, data)
        self.assertEqual(w.mod_identity(self.live, 'mods/a.jar'), 'example')
        self.assertEqual(w.mod_identity(self.live, 'mods/b.jar.disabled'), 'legacy')

    def test_mod_metadata_and_names_do_not_come_from_filename(self):
        mods = self.live / 'mods'
        mods.mkdir()
        entries = [('renamed.jar', 'mcmod.info', '[{"modid":"legacy","name":"Legacy Mod","version":"1.0"}]'),
                   ('neo.jar', 'META-INF/neoforge.mods.toml', '[[mods]]\nmodId="modern"\ndisplayName="Modern Mod"\nversion="2.0"')]
        for filename, metadata, content in entries:
            with zipfile.ZipFile(mods / filename, 'w') as jar:
                jar.writestr(metadata, content)
        data = self.worker.inventory(self.server)
        self.assertEqual(data['metadata']['mods/renamed.jar']['name'], 'Legacy Mod')
        self.assertEqual(data['metadata']['mods/renamed.jar']['identity'], 'legacy')
        self.assertEqual(data['metadata']['mods/neo.jar']['loader'], 'neoforge')
        self.assertEqual(data['metadata']['mods/neo.jar']['version'], '2.0')
        self.put('mods/guessed-filename-1.0.jar', b'not a real jar')
        self.assertIsNone(w.mod_metadata(self.live, 'mods/guessed-filename-1.0.jar'))

    def test_per_server_inventory_directories_and_unscanned_capability(self):
        self.put('custom-tools/readme.txt')
        self.assertIn('custom-tools', self.worker.inventory(self.server)['excluded_roots'])
        self.settings['servers'][self.server]['managed_directories'] = ['custom-tools']
        self.assertIn('custom-tools/readme.txt', self.worker.inventory(self.server)['files'])
        self.put('custom-tools/DIM42/region/r.0.0.mca', b'world must not be read')
        self.assertNotIn('custom-tools/DIM42/region/r.0.0.mca', self.worker.inventory(self.server)['files'])
        with self.assertRaises(ValueError):
            self.worker.hashes(self.server, ['custom-tools/DIM42/region/r.0.0.mca'])

    def test_custom_world_variants_and_whitespace_properties(self):
        self.put('server.properties', b'level-name custom\n')
        for name in ('custom', 'custom_nether', 'custom_the_end'):
            self.put(name + '/data/x', b'world')
        self.settings['servers'][self.server]['managed_directories'] = ['custom', 'custom_nether', 'custom_the_end']
        self.assertEqual(set(self.worker.inventory(self.server)['files']), {'server.properties'})
        with self.assertRaises(ValueError):
            self.worker.hashes(self.server, ['custom_nether/data/x'])

    def test_restart_marks_operations_interrupted_without_replay(self):
        op = self.stage / ('a' * 32)
        op.mkdir()
        w.atomic_json(op / 'operation.json', {'state': 'downloading', 'operation_id': 'a' * 32})
        second = w.Worker(self.settings)
        self.assertEqual(second.status(self.server, 'a' * 32)['state'], 'interrupted')
        self.assertEqual(second.threads, {})

    def test_cross_server_operation_id_is_not_accessible(self):
        with self.assertRaises(FileNotFoundError):
            self.worker.status(self.server, 'a' * 32)
        with self.assertRaises(ValueError):
            self.worker.status(self.server, '../escape')

    def test_mrpack_overrides_and_client_environment(self):
        unpack = self.root / 'mrpack'
        unpack.mkdir()
        (unpack / 'modrinth.index.json').write_text(json.dumps({'formatVersion': 1, 'game': 'minecraft', 'dependencies': {'minecraft': '1.20.1', 'fabric-loader': '0.16.0'}, 'files': [{'path': 'mods/client.jar', 'env': {'server': 'unsupported'}}]}))
        for folder, content in [('overrides', b'default'), ('server-overrides', b'server'), ('client-overrides', b'client')]:
            (unpack / folder / 'config').mkdir(parents=True)
            (unpack / folder / 'config/a.cfg').write_bytes(content)
        files = self.root / 'resolved'
        files.mkdir()
        runtime = self.worker._mrpack(self.server, unpack, files, 1000, lambda: False, lambda **v: None)
        self.assertEqual((files / 'config/a.cfg').read_bytes(), b'server')
        self.assertFalse((files / 'mods/client.jar').exists())
        self.assertEqual(runtime['loader_version'], '0.16.0')

    def test_mrpack_required_missing_download_fails(self):
        unpack = self.root / 'mrpack'
        unpack.mkdir()
        (unpack / 'modrinth.index.json').write_text(json.dumps({'formatVersion': 1, 'game': 'minecraft', 'files': [{'path': 'mods/required.jar', 'hashes': {'sha1': 'a' * 40}}]}))
        with self.assertRaises(ValueError):
            self.worker._mrpack(self.server, unpack, self.root / 'files', 1000, lambda: False, lambda **v: None)

    def recipe(self, artifact):
        self.settings['servers'][self.server]['artifacts'] = {'fixture': str(artifact)}
        return {'provider': 'custom', 'project': 'fixture-project', 'version': '1.0', 'format': 'zip',
                'downloads': [{'artifact_id': 'fixture', 'archive': True, 'hashes': {'sha256': w.hashlib.sha256(artifact.read_bytes()).hexdigest()}}]}

    def test_complete_local_artifact_preparation_and_idempotent_reconciliation(self):
        artifact = self.archive([('config/custom.cfg', b'upstream'), ('mods/example.jar', b'not-a-zip-fixture')])
        recipe = self.recipe(artifact)
        op = 'a' * 32
        self.worker.prepare(self.server, recipe, op)
        self.worker.threads[(self.server, op)].join(5)
        status = self.worker.status(self.server, op)
        self.assertEqual(status['state'], 'completed', status)
        manifest = self.worker.handle(self.server, 'manifest', {'operation_id': op})
        self.assertEqual(manifest['files']['config/custom.cfg'], w.hashlib.sha256(b'upstream').hexdigest())
        self.assertEqual(manifest['verification'], 'upstream-checksums')
        self.assertFalse((self.live / 'config/custom.cfg').exists())
        self.assertEqual(self.worker.prepare(self.server, recipe, op)['state'], 'completed')
        recipe['version'] = 'wrong'
        with self.assertRaises(ValueError):
            self.worker.prepare(self.server, recipe, op)

    def test_failed_preparation_never_returns_a_manifest(self):
        artifact = self.archive([('config/custom.cfg', b'upstream')])
        recipe = self.recipe(artifact)
        recipe['downloads'][0]['hashes']['sha256'] = '0' * 64
        op = 'b' * 32
        self.worker.prepare(self.server, recipe, op)
        self.worker.threads[(self.server, op)].join(5)
        self.assertEqual(self.worker.status(self.server, op)['state'], 'failed')
        with self.assertRaises(ValueError):
            self.worker.handle(self.server, 'manifest', {'operation_id': op})

    def test_failed_extraction_persists_failure_and_leaves_live_files_intact(self):
        artifact = self.archive([('../traversal', b'bad')])
        self.put('config/local.cfg', b'customized')
        recipe = self.recipe(artifact)
        op = 'c' * 32
        self.worker.prepare(self.server, recipe, op)
        self.worker.threads[(self.server, op)].join(5)
        self.assertEqual(self.worker.status(self.server, op)['state'], 'failed')
        self.assertEqual((self.live / 'config/local.cfg').read_bytes(), b'customized')

    def test_http_server_scope_authentication(self):
        httpd = w.BoundedHTTPServer(('127.0.0.1', 0), w.Handler)
        httpd.worker = self.worker
        thread = threading.Thread(target=httpd.serve_forever, daemon=True)
        thread.start()
        self.addCleanup(httpd.server_close)
        self.addCleanup(httpd.shutdown)
        client = w.http.client.HTTPConnection('127.0.0.1', httpd.server_port, timeout=3)
        path = '/v1/servers/' + self.server + '/capabilities'
        client.request('POST', path, '{}', headers={'Authorization': 'Bearer wrong'})
        response = client.getresponse()
        self.assertEqual(response.status, 403)
        response.read()
        client.request('POST', path, '{}', headers={'Authorization': 'Bearer ' + 'a' * 64})
        response = client.getresponse()
        self.assertEqual(response.status, 200)
        self.assertFalse(json.loads(response.read())['deploy'])
        client.close()

    def begin_curseforge(self):
        manifest = {'manifestType': 'minecraftModpack', 'manifestVersion': 1, 'overrides': 'overrides',
                    'minecraft': {'version': '1.20.1', 'modLoaders': [{'id': 'forge-47.4.0', 'primary': True}]},
                    'files': [{'projectID': 123, 'fileID': 20, 'required': True}]}
        artifact = self.archive([('manifest.json', json.dumps(manifest)), ('overrides/config/a.cfg', b'upstream config')])
        recipe = self.recipe(artifact)
        recipe.update(provider='curseforge', project='123', version='999', format='curseforge')
        operation = 'd' * 32
        self.worker.prepare(self.server, recipe, operation)
        self.worker.threads[(self.server, operation)].join(5)
        self.assertEqual(self.worker.status(self.server, operation)['state'], 'awaiting_resolution')
        return operation, self.worker.requirements(self.server, operation)

    def resolved_mod(self):
        payload = io.BytesIO()
        with zipfile.ZipFile(payload, 'w') as jar:
            jar.writestr('mcmod.info', '[{"modid":"fixture"}]')
        data = payload.getvalue()
        return data, [{'project_id': '123', 'file_id': '20', 'path': 'mods/fixture.jar',
                       'url': 'https://github.com/fixture.jar', 'hashes': {'sha1': w.hashlib.sha1(data).hexdigest()}, 'size': len(data)}]

    def test_curseforge_waits_for_complete_pinned_api_resolution(self):
        operation, requirements = self.begin_curseforge()
        with self.assertRaises(ValueError):
            self.worker.handle(self.server, 'manifest', {'operation_id': operation})
        with self.assertRaises(ValueError):
            self.worker.resolve(self.server, operation, requirements['sha256'], [])
        data, files = self.resolved_mod()
        wrong = [dict(files[0], file_id='21')]
        with self.assertRaises(ValueError):
            self.worker.resolve(self.server, operation, requirements['sha256'], wrong)
        with self.assertRaises(ValueError):
            self.worker.resolve(self.server, operation, '0' * 64, files)
        self.assertEqual(self.worker.status(self.server, operation)['state'], 'awaiting_resolution')

    def test_curseforge_downloads_all_mods_applies_overrides_and_verifies_manifest(self):
        operation, requirements = self.begin_curseforge()
        data, files = self.resolved_mod()
        response = unittest.mock.MagicMock()
        response.status = 200
        response.getheader.side_effect = lambda key, default=None: str(len(data)) if key == 'Content-Length' else default
        response.read.side_effect = io.BytesIO(data).read
        with patch.object(w, 'public_address', return_value='1.1.1.1'), patch.object(w, 'PinnedHTTPS') as transport:
            transport.return_value.getresponse.return_value = response
            self.worker.resolve(self.server, operation, requirements['sha256'], files)
            self.worker.threads[(self.server, operation)].join(5)
        result = self.worker.status(self.server, operation)
        self.assertEqual(result['state'], 'completed', result)
        upstream = self.worker.handle(self.server, 'manifest', {'operation_id': operation})
        self.assertEqual(upstream['files']['mods/fixture.jar'], w.hashlib.sha256(data).hexdigest())
        self.assertEqual(upstream['files']['config/a.cfg'], w.hashlib.sha256(b'upstream config').hexdigest())
        self.assertEqual(upstream['identities']['mods/fixture.jar'], 'fixture')
        self.assertEqual(upstream['runtime']['loader_version'], '47.4.0')
        self.assertEqual(len(upstream['artifacts']), 2)
        self.assertFalse(any(self.live.iterdir()))
        self.assertEqual(self.worker.resolve(self.server, operation, requirements['sha256'], files)['state'], 'completed')
        with self.assertRaises(ValueError):
            self.worker.resolve(self.server, operation, requirements['sha256'], [])

    def test_curseforge_failed_required_transfer_never_completes(self):
        operation, requirements = self.begin_curseforge()
        data, files = self.resolved_mod()
        response = unittest.mock.MagicMock()
        response.status = 200
        response.getheader.return_value = None
        response.read.side_effect = io.BytesIO(b'truncated or changed artifact').read
        with patch.object(w, 'public_address', return_value='1.1.1.1'), patch.object(w, 'PinnedHTTPS') as transport:
            transport.return_value.getresponse.return_value = response
            self.worker.resolve(self.server, operation, requirements['sha256'], files)
            self.worker.threads[(self.server, operation)].join(5)
        self.assertEqual(self.worker.status(self.server, operation)['state'], 'failed')
        with self.assertRaises(ValueError):
            self.worker.handle(self.server, 'manifest', {'operation_id': operation})
        self.assertFalse(any(self.live.iterdir()))

    def test_curseforge_waiting_resolution_survives_restart_and_cancels(self):
        operation, requirements = self.begin_curseforge()
        restarted = w.Worker(self.settings)
        self.assertEqual(restarted.requirements(self.server, operation)['sha256'], requirements['sha256'])
        status = restarted.handle(self.server, 'cancel', {'operation_id': operation})
        self.assertEqual(status['state'], 'cancelled')
        with self.assertRaises(ValueError):
            restarted.resolve(self.server, operation, requirements['sha256'], self.resolved_mod()[1])

    def test_curseforge_rejects_optional_and_duplicate_projects(self):
        bad = {'manifestType': 'minecraftModpack', 'manifestVersion': 1,
               'files': [{'projectID': 123, 'fileID': 20, 'required': False}]}
        with self.assertRaises(ValueError):
            self.worker.curseforge_requirements(bad)
        bad['files'] = [{'projectID': 123, 'fileID': 20, 'required': True}] * 2
        with self.assertRaises(ValueError):
            self.worker.curseforge_requirements(bad)


class DownloadTests(unittest.TestCase):
    def response(self, body=b'content', status=200, headers=None):
        response = unittest.mock.MagicMock()
        response.status = status
        data = io.BytesIO(body)
        response.read.side_effect = data.read
        response.getheader.side_effect = lambda k, default=None: (headers or {}).get(k, default)
        return response

    def run_fetch(self, responses, expected=None, cancelled=lambda: False):
        with tempfile.TemporaryDirectory() as temp, patch.object(w, 'public_address', return_value='1.1.1.1'), patch.object(w, 'PinnedHTTPS') as transport:
            transport.return_value.getresponse.side_effect = responses
            return w.fetch('https://github.com/fixture', Path(temp) / 'file', ['github.com'],
                           {'sha256': expected or w.hashlib.sha256(b'content').hexdigest()}, 1000, cancelled=cancelled)

    def test_streamed_download_verifies_checksum(self):
        digest, size = self.run_fetch([self.response()])
        self.assertEqual(size, 7)
        self.assertEqual(digest, w.hashlib.sha256(b'content').hexdigest())

    def test_checksum_mismatch(self):
        with self.assertRaises(ValueError):
            self.run_fetch([self.response()], 'a' * 64)

    def test_interrupted_transfer(self):
        with self.assertRaises(ValueError):
            self.run_fetch([self.response(headers={'Content-Length': '30'})])

    def test_unsafe_redirect_rejected(self):
        with self.assertRaises(ValueError):
            self.run_fetch([self.response(status=302, headers={'Location': 'http://127.0.0.1/private'})])

    def test_cancelled_download(self):
        with self.assertRaises(InterruptedError):
            self.run_fetch([self.response()], cancelled=lambda: True)

    def test_private_dns_and_mapped_loopback_rejected(self):
        for address in ('127.0.0.1', '10.0.0.1', '169.254.169.254', '::1', '::ffff:127.0.0.1'):
            with self.subTest(address=address), patch.object(w.socket, 'getaddrinfo', return_value=[(2, 1, 6, '', (address, 443))]), self.assertRaises(ValueError):
                w.public_address('github.com')


if __name__ == '__main__':
    unittest.main()
