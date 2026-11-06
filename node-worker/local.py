#!/usr/bin/env python3
"""One bounded stdio call from Laravel; no listener, shell, installer or live writes."""
import fcntl
import json
import os
from pathlib import Path
import sys

from worker import Worker, MAX_REQUEST, ManagedPathFailure


class LocalError(Exception):
    pass


def public_result(result):
    if result.get('state') != 'failed':
        return result
    result = dict(result)
    detail = result.pop('diagnostic', None)
    if (isinstance(detail, dict) and detail.get('schema') == 1
            and detail.get('code') in ('UNAPPROVED_MANAGED_DIRECTORY', 'PROTECTED_MANAGED_PATH')
            and isinstance(detail.get('directories'), list) and len(detail['directories']) <= 16
            and all(isinstance(s, str) for s in detail['directories'])):
        safe = ManagedPathFailure(detail['directories'], protected=detail['code'] == 'PROTECTED_MANAGED_PATH').diagnostic
        result.update(diagnostic=safe, error=safe['description'])
    else:
        result['error'] = 'Local preparation failed checksum, download, archive, permissions or managed-path validation. Check the reviewed recipe and retained stage; live files were not changed.'
    # Preserve the original private worker record; never replace evidence with a display fallback.
    return result


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


def node_capabilities(node):
    if sys.version_info < (3, 11) or os.geteuid() == 0 or node['uid'] != os.geteuid():
        raise LocalError('runtime')
    live, stage = Path(node['volume_root']), Path(node['staging_parent'])
    for path in (live, stage):
        if not path.is_absolute() or path == Path('/') or not path.is_dir() or path.resolve() != path:
            raise LocalError('paths')
    if live == stage or live in stage.parents or stage in live.parents:
        raise LocalError('paths')
    if (stage.stat().st_uid != os.geteuid() or stage.stat().st_mode & 0o077
            or not os.access(stage, os.R_OK | os.W_OK | os.X_OK)
            or not os.access(live, os.R_OK | os.X_OK)):
        raise LocalError('permissions')
    return {'configuration_ready': True, 'backend': 'local', 'deploy': False}


def provision(request):
    server = request['server']
    import uuid
    if str(uuid.UUID(server)) != server:
        raise LocalError('paths')
    entry = request['servers'][server]
    root, stage = Path(entry['root']), Path(entry['staging'])
    if stage.name != server or root.name != server:
        # Legacy operator mappings already contain an existing private stage; never create arbitrary paths.
        if stage.is_dir():
            return
        raise LocalError('paths')
    node_capabilities({'uid': os.geteuid(), 'volume_root': str(root.parent), 'staging_parent': str(stage.parent)})
    if root.resolve() != root or not root.is_dir() or not os.access(root, os.R_OK | os.X_OK):
        raise LocalError('permissions')
    fd = os.open(stage.parent, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
    try:
        try:
            os.mkdir(server, 0o700, dir_fd=fd)
        except FileExistsError:
            pass  # settings_for below rejects existing symlinks, wrong owners and loose modes.
    finally:
        os.close(fd)


def run(request):
    if request['operation'] == 'node_capabilities':
        return node_capabilities(request['node'])
    if request['operation'] == 'provision':
        if os.geteuid() == 0:
            raise LocalError('runtime')
        provision(request)
        request = {**request, 'operation': 'capabilities'}
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
        return public_result(worker.handle(server, operation, payload))

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
        return public_result(result)
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
