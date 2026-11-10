"""Test-only DNS/pinned-socket routing. Production worker/TLS policy stays unchanged."""
import json
import os
import socket

path = os.environ.get('ARCTURUS_TEST_TLS_ROUTING')
if path:
    with open(path) as source:
        route = json.load(source)
    original_dns = socket.getaddrinfo
    original_connect = socket.create_connection

    def dns(host, port, *args, **kwargs):
        if host in route['hosts']:
            address = '127.0.0.1' if host == 'private.example.test' else '93.184.216.34'
            return [(socket.AF_INET, socket.SOCK_STREAM, 6, '', (address, port))]
        return original_dns(host, port, *args, **kwargs)

    def connect(address, *args, **kwargs):
        if address == ('93.184.216.34', 443):
            address = ('127.0.0.1', route['port'])
        return original_connect(address, *args, **kwargs)

    socket.getaddrinfo = dns
    socket.create_connection = connect
