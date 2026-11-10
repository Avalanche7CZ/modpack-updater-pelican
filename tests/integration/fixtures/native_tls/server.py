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
hosts = ['github.com', 'release-assets.githubusercontent.com', 'cdn.modrinth.com', 'files.feed-the-beast.com', 'custom.example.test', 'private.example.test']
subprocess.run(['openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-days', '1', '-subj', '/CN=github.com',
                '-addext', 'subjectAltName=' + ','.join('DNS:' + h for h in hosts), '-keyout', str(root / 'key.pem'), '-out', str(root / 'cert.pem')], check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
buffer = io.BytesIO()
with zipfile.ZipFile(buffer, 'w') as archive:
    archive.writestr('config/options.cfg', 'verified upstream')
body = buffer.getvalue()
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
        redirects = {'/redirect': 'https://release-assets.githubusercontent.com/archive?signature=fixture-secret',
                     '/retry': 'https://release-assets.githubusercontent.com/archive',
                     '/bad': 'https://unapproved.example.test/archive?token=fixture-secret',
                     '/retry-bad': 'https://unapproved.example.test/archive?token=fixture-secret',
                     '/private': 'https://private.example.test/archive',
                     '/downgrade': 'http://release-assets.githubusercontent.com/archive',
                     '/loop': 'https://github.com/loop'}
        if path in redirects:
            self.send_response(302); self.send_header('Location', redirects[path]); self.end_headers(); return
        self.send_response(200); self.send_header('Content-Length', str(len(body))); self.end_headers(); self.wfile.write(body)

server = http.server.ThreadingHTTPServer(('127.0.0.1', 0), Handler)
context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
context.load_cert_chain(root / 'cert.pem', root / 'key.pem')
server.socket = context.wrap_socket(server.socket, server_side=True)
(root / 'routing.json').write_text(json.dumps({'port': server.server_port, 'hosts': hosts, 'sha256': hashlib.sha256(body).hexdigest()}))
server.serve_forever()
