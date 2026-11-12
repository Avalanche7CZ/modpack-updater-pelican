"""Loopback-only real HTTP fixture: count TCP peers used for directory reads."""
import http.server
import json
import threading

ports = set()
count = 0
class Handler(http.server.BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.1"
    def log_message(self, *args):
        pass
    def do_GET(self):
        global count
        if "/files/list-directory" in self.path:
            ports.add(self.client_address[1])
            count += 1
            data = json.dumps([dict(name="candidate.jar", file=True, directory=False, symlink=False, size=7)]).encode()
        elif "/files/contents" in self.path:
            data = b"fixture"
        elif self.path == "/stats":
            data = json.dumps(dict(listings=count, connections=len(ports))).encode()
        else:
            self.send_error(404)
            return
        self.send_response(200)
        self.send_header("Content-Length", str(len(data)))
        self.send_header("User-Agent", "Pelican Wings/v1.0.0 (id:fixture)")
        self.end_headers()
        self.wfile.write(data)

server = http.server.ThreadingHTTPServer(("127.0.0.1", 0), Handler)
server.daemon_threads = True
print(server.server_port, flush=True)
server.serve_forever()
