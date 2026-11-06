#!/usr/bin/env python3
"""One bounded stdio call from Laravel; no listener, shell, installer or live writes."""
import fcntl
import json
import os
from pathlib import Path
import sys

from worker import Worker, MAX_REQUEST, atomic_json


class LocalError(Exception):
    pass


def settings_for(request):
    if sys.version_info < (3, 11) or os.geteuid() == 0:
        raise LocalError('runtime')
    servers = request['servers']
    for entry in servers.values():
        for key in ('root', 'staging'):
            path = Path(entry[key])
            if not path.is_absolute() or path == Path('/') or not path.is_dir() or path.resolve() != path:
                raise LocalError('paths')
        live, stage = Path(entry['root']), Path(entry['staging'])
        if stage.stat().st_mode & 0o077 or stage.stat().st_uid != os.geteuid():
            raise LocalError('permissions')
        if not os.access(live, os.R_OK | os.X_OK) or not os.access(stage, os.R_OK | os.W_OK | os.X_OK):
            raise LocalError('permissions')
    return {'servers': servers}


def run(request):
    settings = settings_for(request)
    server, operation, payload = request['server'], request['operation'], request.get('payload', {})
    if operation not in {'capabilities', 'hashes', 'inventory', 'prepare', 'status', 'cancel', 'manifest', 'stage_file', 'requirements', 'resolve'}:
        raise LocalError('operation')
    # Reuse identical scope, descriptor-relative hashing, pinned downloads, archive and manifest code.
    # A read/test must not mark another running subprocess interrupted.
    worker = Worker(settings, require_tokens=False, recover_interrupted=False)
    if operation == 'capabilities':
        return {**worker.handle(server, operation, payload), 'configuration_ready': True, 'backend': 'local'}
    if operation not in {'prepare', 'resolve'}:
        return worker.handle(server, operation, payload)

    stage = Path(settings['servers'][server]['staging'])
    # Cross-process lock supplements the existing durable Panel operation exclusion.
    fd = os.open(stage / '.local-preparation.lock', os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW, 0o600)
    try:
        try:
            fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            raise LocalError('busy')
        # Only after acquiring the lock can an unfinished predecessor be declared interrupted.
        worker.recover(server)
        result = worker.handle(server, operation, payload)
        thread = worker.threads.get((server, payload['operation_id']))
        if thread:
            thread.join()
            result = worker.status(server, payload['operation_id'])
        # Error text from downloads/ZIPs can contain private URLs; expose a fixed diagnosis.
        if result.get('state') == 'failed':
            result['error'] = 'Local preparation failed checksum, download, archive, permissions or managed-path validation. Check the reviewed recipe and retained stage; live files were not changed.'
            atomic_json(worker.operation_path(server, payload['operation_id']) / 'operation.json', result)
        return result
    finally:
        os.close(fd)


def main():
    os.umask(0o077)
    try:
        raw = sys.stdin.buffer.read(MAX_REQUEST + 1)
        if len(raw) > MAX_REQUEST:
            raise LocalError('input')
        result = run(json.loads(raw))
        print(json.dumps(result))
        return 0
    except PermissionError:
        code = 'permissions'
    except LocalError as error:
        code = str(error)
    except (ValueError, KeyError, TypeError, OSError):
        code = 'paths'
    print(json.dumps({'code': code}))
    return 1


if __name__ == '__main__':
    sys.exit(main())
