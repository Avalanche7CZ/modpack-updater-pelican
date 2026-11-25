"""Run the real node worker against a deterministic HTTPS response."""
import io
import json
import sys
import zipfile
from pathlib import Path
from unittest.mock import patch

repo = Path(__file__).resolve().parents[3]
sys.path.insert(0, str(repo / 'node-worker'))
import worker

root, server, command = Path(sys.argv[1]), sys.argv[2], sys.argv[3]
paths = root / server
live = paths / 'live'
staging = paths / 'staging'
live.mkdir(parents=True, exist_ok=True)
staging.mkdir(mode=0o700, exist_ok=True)
payload = json.load(sys.stdin)
pack = io.BytesIO()
with zipfile.ZipFile(pack, 'w') as archive:
    for name, content in [('config/fixture.cfg', b'config'), ('mods/fixture.jar', b'fixture')]:
        archive.writestr(zipfile.ZipInfo(name), content)
        file = live / name
        file.parent.mkdir(exist_ok=True)
        file.write_bytes(content)


class Response(io.BytesIO):
    status = 200

    def getheader(self, name, default=None):
        return str(len(pack.getvalue())) if name.lower() == 'content-length' else default

    read1 = io.BytesIO.read


def fixture_download():
    with (paths / 'downloads').open('a') as count:
        count.write('GET\n')
    return Response(pack.getvalue())


node = worker.Worker({'servers': {server: {'root': str(live), 'staging': str(staging),
    'allowed_hosts': ['downloads.gtnewhorizons.com']}}}, require_tokens=False)
with patch.object(worker, 'public_address', return_value='1.1.1.1'), patch.object(worker, 'PinnedHTTPS') as https:
    https.return_value.getresponse.side_effect = fixture_download
    result = node.handle(server, command, payload)
    if command == 'prepare':
        operation_id = payload['operation_id']
        running = node.threads.get((server, operation_id))
        if running:
            running.join(10)
            if running.is_alive():
                raise RuntimeError('Fixture preparation timed out')
        result = node.status(server, operation_id)
print(json.dumps(result))
