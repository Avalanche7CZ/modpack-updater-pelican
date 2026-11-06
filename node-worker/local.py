#!/usr/bin/env python3
"""One bounded stdio call from Laravel; no listener, shell, installer or live writes."""
import fcntl
import json
import os
from pathlib import Path
import sys

from worker import Worker, MAX_REQUEST, ManagedPathFailure, FAILURES, PreparationFailure, diagnostic


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
    elif isinstance(detail, dict) and isinstance(detail.get('code'), str) and detail['code'] in FAILURES:
        safe = diagnostic(PreparationFailure(detail['code'], **(detail['context'] if isinstance(detail.get('context'), dict) else {})), detail.get('phase'))
        result.update(diagnostic=safe, error=safe['description'])
    else:
        safe = diagnostic(PreparationFailure('PREPARATION_FAILED'), 'queued')
        result.update(diagnostic=safe, error=safe['description'])
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


def run(request, observer=None):
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
    worker = Worker(settings, require_tokens=False, recover_interrupted=False, observer=observer)
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
    streaming, phase = False, 'queued'
    request = {}
    lock = __import__('threading').Lock()
    def write(value):
        with lock:
            print(json.dumps(value), flush=True)
    def observe(snapshot):
        nonlocal phase
        phase = snapshot.get('progress', {}).get('phase', phase)
        if streaming:
            write({'type': 'progress', **snapshot})
    def finish(value):
        write({'type': 'result', 'data': value} if streaming else value)
    try:
        raw = sys.stdin.buffer.read(MAX_REQUEST + 1)
        if len(raw) > MAX_REQUEST:
            raise LocalError('input')
        request = json.loads(raw)
        streaming = request.get('progress_stream') is True
        phase = 'comparing' if request.get('operation') == 'inventory' else 'queued'
        result = run(request, observe)
        finish(result)
        return 0
    except Exception as error:
        if isinstance(error, LocalError):
            code = str(error)
            mapped = 'PERMISSION_DENIED' if code == 'permissions' else 'METADATA_INVALID'
            detail = diagnostic(PreparationFailure(mapped), phase)
        else:
            # Typed worker diagnostics cross stdio unchanged; never use exception text as a response.
            code = 'permissions' if isinstance(error, PermissionError) else 'paths'
            detail = diagnostic(PreparationFailure('CANCELLED') if isinstance(error, InterruptedError) else error, phase)
        finish({'code': code, 'diagnostic': detail})
        return 1


if __name__ == '__main__':
    sys.exit(main())
