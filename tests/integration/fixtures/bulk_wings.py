"""Disposable disk-backed HTTP CONTRACT fixture, NOT Wings. Never serves production data."""
import http.server
import json
import os
import pathlib
import shutil
import socket
import tempfile
import threading
import time
import urllib.parse
import zipfile

root = pathlib.Path(tempfile.mkdtemp(prefix='arcturus-wire-fixture-'))
counts, wire = {}, {}
active = peak = 0
mutex = threading.Lock()
fault = ''
latency = float(os.getenv('BULK_FIXTURE_LATENCY_MS', '0')) / 1000

def resolve(p):
    path = root / str(p).lstrip('/')
    if root not in path.resolve().parents and path.resolve() != root:
        raise ValueError('escape')
    return path

def stat(p):
    st = p.lstat()
    return dict(name=p.name, file=p.is_file(), directory=p.is_dir(), symlink=p.is_symlink(), size=st.st_size,
                modified=str(st.st_mtime_ns), mode_bits=oct(st.st_mode & 0o777)[2:])

class Handler(http.server.BaseHTTPRequestHandler):
    protocol_version = 'HTTP/1.1'
    def setup(self):
        super().setup()
        self.connection.setsockopt(socket.IPPROTO_TCP, socket.TCP_NODELAY, 1)
    def log_message(self, *args): pass
    def reply(self, code=204, body=None):
        data = b'' if body is None else json.dumps(body).encode()
        route = urllib.parse.urlparse(self.path).path.rsplit('/', 1)[-1]
        if route not in ('stats', 'fault'):
            with mutex: wire['response_' + route] = wire.get('response_' + route, 0) + len(data)
        self.send_response(code)
        self.send_header('User-Agent', 'Pelican Wings/v1.0.0 (id:fixture)')
        self.send_header('Content-Length', str(len(data)))
        self.end_headers()
        self.wfile.write(data)
    def run_request(self):
        global active, peak, fault
        url = urllib.parse.urlparse(self.path)
        route = url.path.rsplit('/', 1)[-1]
        q = urllib.parse.parse_qs(url.query)
        if route == 'stats':
            return self.reply(200, dict(requests=counts, bytes=wire, peak_reads=peak, fault=fault))
        if route == 'fault':
            fault = q.get('value', [''])[0]
            return self.reply()
        requested_fault = fault
        with mutex:
            counts[route] = counts.get(route, 0) + 1
            if route == 'contents': active += 1; peak = max(peak, active)
        try:
            if latency: time.sleep(latency)
            if route == 'list-directory':
                return self.reply(200, [stat(p) for p in resolve(q['directory'][0]).iterdir()])
            if route == 'contents':
                p = resolve(q['file'][0]); size = p.stat().st_size
                self.send_response(200)
                self.send_header('User-Agent', 'Pelican Wings/v1.0.0 (id:fixture)')
                self.send_header('Content-Length', str(size)); self.end_headers()
                with p.open('rb') as f: shutil.copyfileobj(f, self.wfile, 1048576)
                with mutex: wire[route] = wire.get(route, 0) + size
                return
            if route == 'write':
                size = int(self.headers['Content-Length']); p = resolve(q['file'][0]); p.parent.mkdir(parents=True, exist_ok=True)
                if requested_fault == 'space':
                    self.rfile.read(size)
                    return self.reply(409)
                with p.open('wb') as f:
                    remaining = size
                    while remaining:
                        chunk = self.rfile.read(min(1048576, remaining))
                        if not chunk: raise ValueError('truncated upload')
                        f.write(chunk); remaining -= len(chunk)
                wire[route] = wire.get(route, 0) + size
                return self.reply()
            body = self.rfile.read(int(self.headers.get('Content-Length', 0)))
            with mutex: wire['request_' + route] = wire.get('request_' + route, 0) + len(body)
            payload = json.loads(body or b'{}')
            directory = resolve(payload.get('root', '/'))
            if route == 'create-directory':
                resolve(payload['path'] + '/' + payload['name']).mkdir()
            elif route == 'decompress':
                if requested_fault == 'unknown': time.sleep(3)
                with zipfile.ZipFile(directory / payload['file']) as archive:
                    for i, name in enumerate(archive.namelist()):
                        if requested_fault == 'missing' and i == 0: continue
                        with archive.open(name) as src, (directory / name).open('wb') as dst: shutil.copyfileobj(src, dst)
                        if requested_fault in ('interrupted', 'unknown'):
                            return self.reply(500)
                    if requested_fault == 'corrupt':
                        with (directory / archive.namelist()[0]).open('r+b') as f: f.write(b'!')
                    if requested_fault == 'extra': (directory / 'extra').write_bytes(b'extra')
            elif route == 'compress':
                if requested_fault == 'compress_unknown': time.sleep(3)
                name = payload['name'] + '.zip'
                with zipfile.ZipFile(directory / name, 'x', zipfile.ZIP_DEFLATED) as archive:
                    for f in payload['files']: archive.write(directory / f, f)
                    if requested_fault == 'readback_extra': archive.writestr('extra', b'extra')
                return self.reply(200, stat(directory / name))
            elif route == 'chmod':
                for row in payload['files']: (directory / row['file']).chmod(int(row['mode'], 8))
            elif route == 'rename':
                for i, row in enumerate(payload['files']):
                    if requested_fault == 'partial' and i == 1: break
                    (directory / row['from']).rename(directory / row['to'])
            elif route == 'delete':
                for name in payload['files']:
                    p = directory / name
                    shutil.rmtree(p) if p.is_dir() else p.unlink()
            else: return self.reply(404)
            return self.reply()
        finally:
            if route == 'contents':
                with mutex: active -= 1
    def do_GET(self): self.run_request()
    def do_POST(self): self.run_request()
    def do_PUT(self): self.run_request()

server = http.server.ThreadingHTTPServer(('127.0.0.1', 0), Handler)
server.daemon_threads = True
print(json.dumps(dict(port=server.server_port, root=str(root))), flush=True)
try: server.serve_forever()
finally: shutil.rmtree(root)
