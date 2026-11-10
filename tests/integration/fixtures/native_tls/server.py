"""Small actual TLS endpoint for Native -> LocalWorker -> Python downloader tests."""
import hashlib
import http.server
import io
import json
from pathlib import Path
import ssl
import subprocess
import sys
import zipfile

root = Path(sys.argv[1])
hosts = ['github.com', 'release-assets.githubusercontent.com', 'cdn.modrinth.com', 'files.feed-the-beast.com', 'edge.forgecdn.net', 'mediafilez.forgecdn.net', 'custom.example.test', 'private.example.test']
subprocess.run(['openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-days', '1', '-subj', '/CN=github.com',
                '-addext', 'subjectAltName=' + ','.join('DNS:' + h for h in hosts), '-keyout', str(root / 'key.pem'), '-out', str(root / 'cert.pem')], check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
buffer = io.BytesIO()
with zipfile.ZipFile(buffer, 'w') as archive:
    archive.writestr('config/options.cfg', 'verified upstream')
body = buffer.getvalue()
client = io.BytesIO()
with zipfile.ZipFile(client, 'w') as archive:
    archive.writestr('manifest.json', json.dumps({'manifestType': 'minecraftModpack', 'manifestVersion': 1, 'overrides': 'overrides',
        'minecraft': {'version': '1.20.1', 'modLoaders': [{'id': 'forge-47.4.0', 'primary': True}]},
        'files': [{'projectID': 1, 'fileID': 2, 'required': True}]}))
    archive.writestr('overrides/config/options.cfg', 'verified override')
client_body = client.getvalue()
jar_body = b'verified resolved dependency'
counts = {}

class Handler(http.server.BaseHTTPRequestHandler):
    def log_message(self, *args):
        pass

    def do_GET(self):
        path = self.path.split('?')[0]
        counts[path] = counts.get(path, 0) + 1
        (root / 'requests.json').write_text(json.dumps(counts))
        if path in ('/retry', '/retry-bad') and counts[path] == 1:
            self.send_response(503); self.end_headers(); return
        redirects = {'/dependency': 'https://mediafilez.forgecdn.net/jar', '/redirect': 'https://release-assets.githubusercontent.com/archive?signature=fixture-secret',
                     '/retry': 'https://release-assets.githubusercontent.com/archive',
                     '/bad': 'https://unapproved.example.test/archive?token=fixture-secret',
                     '/retry-bad': 'https://unapproved.example.test/archive?token=fixture-secret',
                     '/private': 'https://private.example.test/archive',
                     '/downgrade': 'http://release-assets.githubusercontent.com/archive',
                     '/loop': 'https://github.com/loop'}
        if path in redirects:
            self.send_response(302); self.send_header('Location', redirects[path]); self.end_headers(); return
        content = client_body if path == '/client' else jar_body if path == '/jar' else body
        self.send_response(200); self.send_header('Content-Length', str(len(content))); self.end_headers(); self.wfile.write(content)

server = http.server.ThreadingHTTPServer(('127.0.0.1', 0), Handler)
context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
context.load_cert_chain(root / 'cert.pem', root / 'key.pem')
server.socket = context.wrap_socket(server.socket, server_side=True)
(root / 'routing.json').write_text(json.dumps({'port': server.server_port, 'hosts': hosts, 'sha256': hashlib.sha256(body).hexdigest(), 'client_sha256': hashlib.sha256(client_body).hexdigest(), 'jar_sha256': hashlib.sha256(jar_body).hexdigest()}))
server.serve_forever()
