#!/usr/bin/env python3
"""Collector tests use a local fake Docker executable; no Docker/socket/server access."""
import importlib.util
import json
import os
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

spec = importlib.util.spec_from_file_location("collector", Path(__file__).parents[1] / "bin/collect-running-original-evidence.py")
collector = importlib.util.module_from_spec(spec)
spec.loader.exec_module(collector)
SERVER = "bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb"
OPERATION = "aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa"


class CollectorFixture(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix="arcturus-collector-")
        self.root = Path(self.temp.name).resolve()
        fake = self.root / "docker"
        fake.write_text('''#!/usr/bin/env python3
import json, os, pathlib, sys
root=pathlib.Path(os.environ['COLLECTOR_FIXTURE'])
with (root/'requests').open('a') as f: f.write(json.dumps(sys.argv[1:])+'\\n')
if sys.argv[2]=='inspect':
    counter=root/'count'
    n=int(counter.read_text())+1 if counter.exists() else 1
    counter.write_text(str(n))
    item={'Id':'a'*64,'Name':'/bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb','RestartCount':0,
          'State':{'Running':True,'Pid':1234,'StartedAt':'2026-11-14T00:00:00.123456789Z'},
          'Config':{'Env':['SECRET=PRIVATE']}}
    if os.environ.get('COLLECTOR_RESTART') and n>1: item['State']['Pid']=4321
    print(json.dumps([item]))
else:
    print('2026-11-14T00:00:01.000000000Z Java is OpenJDK, version 25.0.1')
''')
        fake.chmod(0o700)
        self.env = patch.dict(os.environ, {"PATH": str(self.root) + os.pathsep + os.environ["PATH"], "COLLECTOR_FIXTURE": str(self.root)})
        self.env.start()

    def tearDown(self):
        self.env.stop()
        self.temp.cleanup()

    def test_private_bundle_and_only_read_commands(self):
        output = self.root / "bundle"
        collector.collect(OPERATION, SERVER, output)
        metadata = json.loads((output / "metadata.json").read_text())
        self.assertEqual(metadata['inspect_before'], metadata['inspect_after'])
        self.assertNotIn('PRIVATE', json.dumps(metadata))
        self.assertNotIn('Config', metadata['inspect_before'])
        for path in [output, output/'console.log', output/'metadata.json']:
            self.assertEqual(path.stat().st_mode & 0o077, 0)
        commands = [json.loads(row) for row in (self.root/'requests').read_text().splitlines()]
        self.assertEqual([row[:2] for row in commands], [['container','inspect'],['container','logs'],['container','inspect']])
        self.assertIn('--timestamps',commands[1])
        self.assertIn('--since',commands[1])
        with self.assertRaises(FileExistsError):
            collector.collect(OPERATION, SERVER, output)

    def test_changed_container_leaves_no_complete_metadata(self):
        with patch.dict(os.environ, {'COLLECTOR_RESTART':'1'}):
            with self.assertRaises(ValueError):
                collector.collect(OPERATION, SERVER, self.root/'changed')
        self.assertFalse((self.root/'changed'/'metadata.json').exists())

    def test_uuid_validation_precedes_docker_or_output(self):
        with self.assertRaises(ValueError):
            collector.collect(OPERATION, '--help', self.root/'invalid')
        self.assertFalse((self.root/'requests').exists())
        self.assertFalse((self.root/'invalid').exists())


if __name__ == '__main__':
    unittest.main()
