#!/usr/bin/env python3
"""Arcturus's server-scoped integrity/staging worker. No subprocess or shell API."""
import argparse
import contextlib
import hashlib
import hmac
import http.client
import ipaddress
import json
import os
from pathlib import Path
import re
import socket
import ssl
import stat
import threading
import time
import uuid
import zipfile
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import urlsplit, urljoin

MANAGED_DIRS = frozenset(('mods', 'config', 'configs', 'scripts', 'kubejs', 'libraries', 'defaultconfigs', 'configureddefaults', 'plugins', 'resources', 'patches', 'datapacks', 'ftbteambases', 'resourcepacks', 'shaderpacks', 'global_packs', 'paxi', 'openloader', 'schematics', 'assets', 'structures'))
RUNTIME = frozenset(('world', 'world_nether', 'world_the_end', 'saves', 'dimensions',
                     'DIM-1', 'DIM1'))
DEFAULT_EXCLUDED = frozenset(('logs', 'crash-reports', 'backups', 'backup', 'data'))
MAX_REQUEST = 16 * 1024 * 1024
MAX_FILES = 100000


def additional_directories(values):
    reserved = {s.lower() for s in RUNTIME | DEFAULT_EXCLUDED} | {'region', 'playerdata', 'entities', 'poi', 'level.dat', 'level.dat_old', 'session.lock'}
    if not isinstance(values, list) or len(values) > 128:
        raise ValueError('Additional managed directories must be a bounded list')
    for value in values:
        if (not isinstance(value, str) or not re.fullmatch(r'[A-Za-z0-9][A-Za-z0-9_.-]{0,63}', value)
                or value.lower() in reserved or re.fullmatch(r'DIM-?[0-9]+', value, re.I)):
            raise ValueError('Additional managed directory is unsafe or reserved for runtime data')
    return sorted(set(values))


class ManagedPathFailure(ValueError):
    def __init__(self, paths, protected=False):
        names = sorted(set(path.split('/')[0] for path in paths))
        safe = [name if re.fullmatch(r'[A-Za-z0-9][A-Za-z0-9_.-]{0,63}', name)
                and not re.search(r'[A-Za-z0-9_-]{40,}|token|secret|password|credential', name, re.I)
                else '[redacted-name]' for name in names[:16]]
        code = 'PROTECTED_MANAGED_PATH' if protected else 'UNAPPROVED_MANAGED_DIRECTORY'
        description = ('Protected runtime/world paths cannot enter a baseline: ' if protected else 'Unapproved managed directories: ') + ', '.join(safe)
        action = ('World/runtime exclusions cannot be overridden.' if protected else
                  'Review the official pack contents, then have an administrator approve these names through local node setup (or the remote worker configuration). PHP cannot grant filesystem permissions.')
        self.diagnostic = {'schema': 1, 'phase': 'hashing', 'code': code,
                           'directories': safe, 'description': description, 'action': action}
        super().__init__(description + '. ' + action)


# Public diagnostics are constructed from this vocabulary, never from exception strings.
FAILURES = {
    'CHECKSUM_MISSING': ('An independently verified upstream checksum is missing.', 'Select a release with published checksums or an operator-reviewed verified recipe.'),
    'CHECKSUM_INVALID': ('The declared upstream checksum is invalid.', 'Correct the reviewed release metadata; do not disable checksum verification.'),
    'CHECKSUM_MISMATCH': ('Downloaded bytes do not match the upstream checksum.', 'Retain the partial stage and verify the exact upstream asset before an explicit retry.'),
    'NETWORK_DNS': ('The download hostname could not be resolved.', 'Check DNS from the Panel/worker host.'),
    'NETWORK_TIMEOUT': ('The upstream connection timed out.', 'Check outbound connectivity and upstream availability before retrying.'),
    'NETWORK_TLS': ('The upstream HTTPS connection could not be verified.', 'Check certificates, system time and trust store. Keep TLS verification enabled.'),
    'NETWORK_IO': ('The upstream connection was interrupted.', 'Check connectivity and retain the partial download before retrying.'),
    'HTTP_STATUS': ('The upstream server rejected the artifact request.', 'Check upstream availability, release asset selection and download permissions.'),
    'DOWNLOAD_POLICY': ('The artifact or redirect violates the HTTPS/public-address/host policy.', 'Review the exact upstream CDN hosts in the operator configuration; do not allow private downloads.'),
    'DOWNLOAD_LIMIT': ('The download exceeds its configured byte limit.', 'Review the expected release size and available staging space before adjusting operator limits.'),
    'TRANSFER_SIZE': ('The transfer ended with a different byte count than declared.', 'Retain the partial download and check the exact upstream asset and connection.'),
    'ARCHIVE_INVALID': ('The downloaded archive is corrupt or uses an unsupported format.', 'Verify the official server ZIP and retain the downloaded evidence.'),
    'ARCHIVE_UNSAFE': ('An archive entry is unsafe, duplicated, encrypted or unsupported.', 'Inspect the numbered entry in the retained archive; use a reviewed normalized server pack.'),
    'ARCHIVE_LIMIT': ('The archive exceeds file-count or expansion safety limits.', 'Review the archive and configured staging limits; do not bypass ZIP safety checks.'),
    'PERMISSION_DENIED': ('A required file or directory could not be accessed.', 'Re-run node setup for managed-file read access and check private staging ownership. Do not grant live write access.'),
    'DISK_FULL': ('The staging filesystem has no available space or quota.', 'Free operator-reviewed staging space without deleting active evidence, or provision more capacity.'),
    'IO_ERROR': ('A filesystem operation failed.', 'Check filesystem health, mount state, staging capacity and permissions.'),
    'MISSING_FILE': ('A required file disappeared or was not present.', 'Inspect retained staging and release contents; do not substitute live files for upstream evidence.'),
    'MANAGED_PATH': ('A file is outside the safe managed-pack scope.', 'Inspect the file/entry identifier in retained staging and use an explicitly reviewed normalized pack. World exclusions cannot be overridden.'),
    'FILES_CHANGED': ('Managed files changed or disappeared during inventory.', 'Retry comparison when files are stable; no incomplete inventory is accepted.'),
    'INVENTORY_LIMIT': ('The managed-file inventory exceeds a safety limit.', 'Review managed scope and configured file/byte limits.'),
    'METADATA_INVALID': ('The release or operation metadata is invalid or incomplete.', 'Review the exact release recipe and retained operation state.'),
    'PREPARATION_FAILED': ('Preparation failed without a recognized safe diagnostic.', 'Inspect retained evidence using the operation IDs. No automatic replay is performed.'),
    'CANCELLED': ('Cancellation was confirmed; partial staging is retained.', 'Start a new operation only after reviewing the retained stage.'),
    'INTERRUPTED': ('The preparation process stopped before completion; staging is retained.', 'Inspect process and operation state before an explicit new attempt.'),
}
PHASES = frozenset(('queued', 'downloading', 'verifying', 'extracting', 'hashing', 'comparing', 'awaiting_resolution'))


class PreparationFailure(ValueError):
    def __init__(self, code, **context):
        self.code = code
        self.context = context
        super().__init__(FAILURES[code][0])


def safe_file(value):
    """Only logical pack paths; unsafe names are represented by a stable, non-reversible ID."""
    if not isinstance(value, str):
        return {}
    result = {'file_id': hashlib.sha256(value.encode('utf-8', errors='replace')).hexdigest()[:12]}
    if (len(value) <= 240 and re.fullmatch(r'[A-Za-z0-9_. /+-]+', value)
            and not value.startswith(('/', '.', ' ')) and all(s not in ('', '.', '..') for s in value.split('/'))
            and (value.split('/')[0] in MANAGED_DIRS or '/' not in value)
            and not re.search(r'[A-Za-z0-9_-]{40,}|token|secret|password|credential|bearer', value, re.I)):
        result['file'] = value
    return result


def diagnostic(error, phase, context=None):
    if isinstance(error, ManagedPathFailure):
        return error.diagnostic
    code = 'PREPARATION_FAILED'
    if isinstance(error, PreparationFailure):
        code = error.code
    elif isinstance(error, InterruptedError):
        code = 'CANCELLED'
    elif isinstance(error, PermissionError):
        code = 'PERMISSION_DENIED'
    elif isinstance(error, FileNotFoundError):
        code = 'MISSING_FILE'
    elif isinstance(error, socket.gaierror):
        code = 'NETWORK_DNS'
    elif isinstance(error, (TimeoutError, socket.timeout)):
        code = 'NETWORK_TIMEOUT'
    elif isinstance(error, ssl.SSLError):
        code = 'NETWORK_TLS'
    elif isinstance(error, (ConnectionError, http.client.HTTPException)):
        code = 'NETWORK_IO'
    elif isinstance(error, (zipfile.BadZipFile, NotImplementedError)):
        code = 'ARCHIVE_INVALID'
    elif isinstance(error, OSError):
        code = 'DISK_FULL' if error.errno in (28, 122) else 'IO_ERROR'
    elif isinstance(error, (KeyError, TypeError, json.JSONDecodeError)):
        code = 'METADATA_INVALID'
    elif isinstance(error, ValueError):
        # Recognize only our fixed legacy messages. Never serialize arbitrary exception text.
        message = str(error)
        if message in ('Unsafe relative path', 'Hash request is outside managed pack scope; world reads are forbidden',
                       'Upstream release contains runtime or unmanaged paths; explicit normalization required',
                       'Duplicate or unsafe Modrinth path', 'Unsafe Modrinth override', 'Unsafe CurseForge override path'):
            code = 'MANAGED_PATH'
        elif message in ('File changed during hashing; retry after the server is stopped',):
            code = 'FILES_CHANGED'
        elif message in ('Inventory byte limit exceeded; no partial inventory returned', 'Inventory byte limit exceeded',
                         'Managed inventory directory/count limit exceeded', 'Inventory file limit exceeded', 'Prepared file limit exceeded'):
            code = 'INVENTORY_LIMIT'
        elif message in ('Download hostname has no address',):
            code = 'NETWORK_DNS'
        elif message in ('Download hostname resolves to a non-public address', 'Artifact redirect limit exceeded'):
            code = 'DOWNLOAD_POLICY'
    supplied = {**(context or {}), **(getattr(error, 'context', {}))}
    safe = {}
    for key in ('artifact_index', 'entry_index', 'http_status', 'file_index'):
        value = supplied.get(key)
        if type(value) is int and 0 <= value <= 1000000:
            safe[key] = value
    safe.update(safe_file(supplied.get('file')))
    if isinstance(supplied.get('file_id'), str) and re.fullmatch('[a-f0-9]{12}', supplied['file_id']):
        safe['file_id'] = supplied['file_id']
    description, action = FAILURES[code]
    return {'schema': 1, 'phase': phase if phase in PHASES else 'queued', 'code': code,
            'description': description, 'action': action, 'context': safe}


class ProgressState:
    """One durable checkpoint per two seconds plus transitions/terminal states; no per-chunk fsync."""
    def __init__(self, path, data, observer=None, clock=time.monotonic):
        self.path, self.data, self.observer, self.clock = path, data, observer, clock
        self.last_write = float('-inf')
        self.last_bytes, self.last_sample, self.speed = None, None, None

    def __call__(self, force=False, **values):
        old = self.data.get('progress', {}).get('phase')
        phase = values.pop('phase', values.get('state'))
        if phase not in PHASES:
            phase = old or 'queued'
        terminal = values.get('state') in ('completed', 'failed', 'cancelled', 'interrupted', 'awaiting_resolution')
        changed = phase != old
        if changed:
            previous = self.data.get('progress', {})
            if old:
                self.data.setdefault('stages', {})[old] = {**previous, 'finished': True}
            self.data['progress'] = {'phase': phase, 'bytes_done': 0, 'bytes_total': None,
                                     'files_done': 0, 'files_total': None}
            self.last_bytes, self.last_sample, self.speed = None, None, None
        progress = self.data.setdefault('progress', {'phase': phase})
        for key in ('bytes_done', 'bytes_total', 'files_done', 'files_total', 'entries_validated', 'entries_scanned', 'step', 'file', 'file_id'):
            if key in values:
                progress[key] = values.pop(key)
        now = self.clock()
        if phase == 'downloading':
            done = progress.get('bytes_done', 0)
            if self.last_sample is not None and now - self.last_sample >= 0.25 and done >= self.last_bytes:
                measured = (done - self.last_bytes) / (now - self.last_sample)
                self.speed = measured if self.speed is None else 0.5 * measured + 0.5 * self.speed
                self.last_sample, self.last_bytes = now, done
            elif self.last_sample is None:
                self.last_sample, self.last_bytes = now, done
            progress['speed_bytes_per_second'] = self.speed
            total = progress.get('bytes_total')
            progress['eta_seconds'] = max(0, (total - done) / self.speed) if total is not None and self.speed and self.speed > 0 else None
        progress['updated_at'] = time.time()
        self.data.update(values, updated_at=time.time())
        progress['artifact_index'] = self.data.get('current_file')
        progress['downloaded_bytes'] = self.data.get('bytes_downloaded')
        progress['download_total_bytes'] = self.data.get('download_total_bytes')
        if 'bytes_downloaded' in values:
            self.data['bytes_downloaded'] = values['bytes_downloaded']
        if values.get('state') == 'completed':
            progress['finished'] = True
        self.data.setdefault('stages', {})[phase] = dict(progress)
        if force or terminal or now - self.last_write >= 2:
            self.last_write = now
            atomic_json(self.path, self.data)
            if self.observer:
                self.observer({'operation_id': self.data.get('operation_id'), 'state': self.data.get('state'),
                               'progress': dict(progress), 'stages': dict(self.data['stages']),
                               'diagnostic': self.data.get('diagnostic')})

    def failed(self, error, **context):
        detail = diagnostic(error, self.data.get('progress', {}).get('phase', self.data.get('state')),
            {**{key: self.data.get('progress', {}).get(key) for key in ('file', 'file_id')}, **context})
        self(force=True, state='failed', diagnostic=detail, error=detail['description'])


def relative(value):
    if not isinstance(value, str) or not value or len(value) > 1024 or '\\' in value or ':' in value:
        raise ValueError('Unsafe relative path')
    if value.startswith('/') or any(x in ('', '.', '..') for x in value.split('/')) or any(ord(x) < 32 or ord(x) == 127 for x in value):
        raise ValueError('Unsafe relative path')
    return value


def fail_walk(error):
    raise error


def atomic_json(path, value):
    temp = path.with_suffix('.tmp-' + uuid.uuid4().hex)
    with temp.open('x') as out:
        os.chmod(temp, 0o600)
        json.dump(value, out, sort_keys=True)
        out.flush()
        os.fsync(out.fileno())
    os.replace(temp, path)


@contextlib.contextmanager
def open_regular(root, path):
    """Pin every path component using directory descriptors, never follow links."""
    parts = relative(path).split('/')
    directory = os.open(root, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
    fd = None
    try:
        for segment in parts[:-1]:
            child = os.open(segment, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=directory)
            os.close(directory)
            directory = child
        fd = os.open(parts[-1], os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK, dir_fd=directory)
        if not stat.S_ISREG(os.fstat(fd).st_mode):
            raise ValueError('Only regular files can be read')
        with os.fdopen(fd, 'rb') as stream:
            fd = None
            yield stream
    finally:
        if fd is not None:
            os.close(fd)
        os.close(directory)


def hash_file(root, path, budget, progress=lambda n: None, cancelled=lambda: False):
    with open_regular(root, path) as source:
        before = os.fstat(source.fileno())
        if before.st_size > budget:
            raise ValueError('Inventory byte limit exceeded; no partial inventory returned')
        digest = hashlib.sha256()
        size = 0
        while chunk := source.read(1024 * 1024):
            if cancelled():
                raise InterruptedError('Operation cancelled')
            size += len(chunk)
            progress(size)
            if size > budget:
                raise ValueError('Inventory byte limit exceeded')
            digest.update(chunk)
        after = os.fstat(source.fileno())
        if (before.st_ino, before.st_size, before.st_mtime_ns, before.st_ctime_ns) != (after.st_ino, after.st_size, after.st_mtime_ns, after.st_ctime_ns):
            raise ValueError('File changed during hashing; retry after the server is stopped')
        return digest.hexdigest(), size


class PinnedHTTPS(http.client.HTTPSConnection):
    def __init__(self, host, address):
        super().__init__(host, timeout=30, context=ssl.create_default_context())
        self.address = address

    def connect(self):
        raw = socket.create_connection((self.address, 443), self.timeout)
        try:
            self.sock = self._context.wrap_socket(raw, server_hostname=self.host)
        except BaseException:
            raw.close()
            raise


def public_address(host):
    addresses = socket.getaddrinfo(host, 443, type=socket.SOCK_STREAM)
    if not addresses:
        raise ValueError('Download hostname has no address')
    for item in addresses:
        address = ipaddress.ip_address(item[4][0])
        if not address.is_global or (address.version == 6 and address.ipv4_mapped and not address.ipv4_mapped.is_global):
            raise ValueError('Download hostname resolves to a non-public address')
    return addresses[0][4][0]


def transfer_report(update, offset, **values):
    if values.get('state') == 'downloading' and type(values.get('bytes_done')) is int:
        values['bytes_downloaded'] = offset + values['bytes_done']
    update(**values)


def validate_checksums(hashes):
    if not isinstance(hashes, dict) or not hashes:
        raise PreparationFailure('CHECKSUM_MISSING')
    for algorithm, value in hashes.items():
        if (algorithm not in ('sha256', 'sha512', 'sha1') or not isinstance(value, str)
                or not re.fullmatch('[a-fA-F0-9]{%d}' % (hashlib.new(algorithm).digest_size * 2), value)):
            raise PreparationFailure('CHECKSUM_INVALID')


def fetch(url, destination, hosts, hashes, maximum, expected_size=None, progress=lambda n: None,
          cancelled=lambda: False, report=lambda **values: None):
    """Pinned HTTPS, streaming checksums and measured progress. Never retry partial mutations."""
    validate_checksums(hashes)
    response = connection = None
    try:
        for _ in range(4):
            try:
                p = urlsplit(url)
                allowed = (len(url) <= 4096 and p.scheme == 'https' and p.hostname in hosts
                           and p.port in (None, 443) and not p.username and not p.password
                           and not p.fragment and not any(ord(c) <= 32 for c in url))
            except ValueError:
                allowed = False
            if not allowed:
                raise PreparationFailure('DOWNLOAD_POLICY')
            connection = PinnedHTTPS(p.hostname, public_address(p.hostname))
            connection.request('GET', p.path + ('?' + p.query if p.query else ''), headers={'User-Agent': 'Arcturus-Modpack-Manager/0.4'})
            response = connection.getresponse()
            if response.status in (301, 302, 303, 307, 308):
                url = urljoin(url, response.getheader('Location', ''))
                response.close(); connection.close()
                continue
            if response.status != 200:
                raise PreparationFailure('HTTP_STATUS', http_status=response.status)
            break
        else:
            raise PreparationFailure('DOWNLOAD_POLICY')
        length = response.getheader('Content-Length')
        if length is not None and (not length.isdigit() or int(length) > maximum):
            raise PreparationFailure('DOWNLOAD_LIMIT')
        total = int(length) if length is not None else expected_size
        report(state='downloading', bytes_done=0, bytes_total=total, files_done=0, files_total=1)
        digests = {a: hashlib.new(a) for a in set(hashes) | {'sha256'}}
        size = 0
        with destination.open('xb') as output:
            while chunk := response.read(1024 * 1024):
                if cancelled():
                    raise InterruptedError('Operation cancelled')
                size += len(chunk)
                if size > maximum:
                    raise PreparationFailure('DOWNLOAD_LIMIT')
                output.write(chunk)
                for digest in digests.values():
                    digest.update(chunk)
                progress(size)
                report(state='downloading', bytes_done=size, bytes_total=total)
            output.flush(); os.fsync(output.fileno())
        if (length is not None and size != int(length)) or (expected_size is not None and size != expected_size):
            raise PreparationFailure('TRANSFER_SIZE')
        report(state='verifying', files_done=0, files_total=len(hashes), bytes_done=0, bytes_total=None,
               step='comparing_streamed_checksums')
        for index, (algorithm, expected) in enumerate(hashes.items()):
            if not hmac.compare_digest(digests[algorithm].hexdigest(), expected.lower()):
                raise PreparationFailure('CHECKSUM_MISMATCH')
            report(state='verifying', files_done=index + 1, files_total=len(hashes))
        return digests['sha256'].hexdigest(), size
    finally:
        if response:
            response.close()
        if connection:
            connection.close()


def local_artifact(identifier, destination, entry, hashes, maximum, report=lambda **values: None, cancelled=lambda: False):
    if not isinstance(identifier, str) or not re.fullmatch('[A-Za-z0-9_-]{1,100}', identifier):
        raise PreparationFailure('METADATA_INVALID')
    configured = entry.get('artifacts', {}).get(identifier)
    if not configured:
        raise PreparationFailure('METADATA_INVALID')
    source = Path(configured)
    if source.resolve() != source or not source.is_file() or Path(entry['root']) in source.parents:
        raise PreparationFailure('DOWNLOAD_POLICY')
    validate_checksums(hashes)
    digests = {a: hashlib.new(a) for a in set(hashes) | {'sha256'}}
    size = 0
    with open_regular(source.parent, source.name) as src, destination.open('xb') as dest:
        total = os.fstat(src.fileno()).st_size
        report(state='downloading', bytes_done=0, bytes_total=total, files_done=0, files_total=1)
        while chunk := src.read(1024 * 1024):
            if cancelled():
                raise InterruptedError('Operation cancelled')
            size += len(chunk)
            if size > maximum:
                raise PreparationFailure('DOWNLOAD_LIMIT')
            dest.write(chunk)
            for digest in digests.values():
                digest.update(chunk)
            report(state='downloading', bytes_done=size, bytes_total=total, bytes_downloaded=size)
        dest.flush(); os.fsync(dest.fileno())
    report(state='verifying', files_done=0, files_total=len(hashes), bytes_done=0, bytes_total=None,
           step='comparing_streamed_checksums')
    for index, (algorithm, expected) in enumerate(hashes.items()):
        if not hmac.compare_digest(digests[algorithm].hexdigest(), expected.lower()):
            raise PreparationFailure('CHECKSUM_MISMATCH')
        report(state='verifying', files_done=index + 1, files_total=len(hashes))
    return digests['sha256'].hexdigest(), size


def extract_zip(archive, destination, maximum, cancelled=lambda: False, report=lambda **values: None):
    """Validate the entire central directory before extracting a single entry."""
    with zipfile.ZipFile(archive) as source:
        entries = source.infolist()
        if len(entries) > MAX_FILES:
            raise PreparationFailure('ARCHIVE_LIMIT')
        seen, total, files_total = set(), 0, sum(not item.is_dir() for item in entries)
        report(state='extracting', step='validating_archive', bytes_done=0, bytes_total=None,
               files_done=0, files_total=files_total, entries_validated=0)
        for index, item in enumerate(entries):
            try:
                name = relative(item.filename.rstrip('/'))
            except ValueError:
                raise PreparationFailure('ARCHIVE_UNSAFE', entry_index=index + 1)
            mode = item.external_attr >> 16
            if name in seen or stat.S_IFMT(mode) not in (0, stat.S_IFREG, stat.S_IFDIR) or item.flag_bits & 1:
                raise PreparationFailure('ARCHIVE_UNSAFE', entry_index=index + 1)
            seen.add(name)
            total += item.file_size
            if total > maximum or item.file_size > max(1048576, item.compress_size * 1000):
                raise PreparationFailure('ARCHIVE_LIMIT', entry_index=index + 1)
            report(state='extracting', entries_validated=index + 1)
        written, files_done = 0, 0
        report(state='extracting', step='extracting_files', bytes_done=0, bytes_total=total)
        for index, item in enumerate(entries):
            if cancelled():
                raise InterruptedError('Operation cancelled')
            dest = destination / item.filename
            if item.is_dir():
                dest.mkdir(parents=True, exist_ok=True, mode=0o750)
                continue
            dest.parent.mkdir(parents=True, exist_ok=True, mode=0o750)
            count = 0
            try:
                with source.open(item) as src, dest.open('xb') as out:
                    while chunk := src.read(1024 * 1024):
                        if cancelled():
                            raise InterruptedError('Operation cancelled')
                        count += len(chunk)
                        if count > item.file_size:
                            raise PreparationFailure('ARCHIVE_INVALID', entry_index=index + 1)
                        out.write(chunk)
                        report(state='extracting', bytes_done=written + count, bytes_total=total)
            except zipfile.BadZipFile:
                raise PreparationFailure('ARCHIVE_INVALID', entry_index=index + 1)
            if count != item.file_size:
                raise PreparationFailure('ARCHIVE_INVALID', entry_index=index + 1)
            os.chmod(dest, 0o750 if (item.external_attr >> 16) & 0o111 else 0o640)
            written += count; files_done += 1
            report(state='extracting', bytes_done=written, bytes_total=total, files_done=files_done)

def mod_metadata(root, path):
    """Bounded JAR metadata, including legacy Forge mcmod.info. Never infer IDs from names."""
    if not path.startswith('mods/') or not (path.lower().endswith('.jar') or path.lower().endswith('.jar.disabled')):
        return None
    try:
        with open_regular(root, path) as stream, zipfile.ZipFile(stream) as jar:
            for name, loader in [('fabric.mod.json', 'fabric'), ('quilt.mod.json', 'quilt'), ('mcmod.info', 'forge'),
                                 ('META-INF/neoforge.mods.toml', 'neoforge'), ('META-INF/mods.toml', 'forge')]:
                if name not in jar.namelist() or jar.getinfo(name).file_size > 1048576:
                    continue
                raw = jar.read(name)
                if name.endswith('.toml'):
                    import tomllib
                    data = tomllib.loads(raw.decode())
                    items, key = data.get('mods', []), 'modId'
                else:
                    data = json.loads(raw)
                    if loader == 'quilt':
                        items, key = [data['quilt_loader']], 'id'
                    elif loader == 'fabric':
                        items, key = [data], 'id'
                    else:
                        items, key = data if isinstance(data, list) else data.get('modList', []), 'modid'
                if not isinstance(items, list) or any(not isinstance(x, dict) for x in items):
                    continue
                ids = sorted(set(x[key].lower() for x in items if isinstance(x.get(key), str) and re.fullmatch(r'[A-Za-z0-9_.-]{1,128}', x[key])))
                if ids:
                    def text(key):
                        value = items[0].get(key)
                        return value[:200] if isinstance(value, str) and '${' not in value and not any(ord(c) < 32 for c in value) else None
                    return {'identity': '+'.join(ids), 'ids': ids, 'loader': loader,
                            'name': text('name') or text('displayName'), 'version': text('version')}
    except (OSError, ValueError, KeyError, TypeError, zipfile.BadZipFile):
        pass
    return None


def mod_identity(root, path):
    metadata = mod_metadata(root, path)
    return metadata['identity'] if metadata else None


class Worker:
    def __init__(self, settings, *, require_tokens=True, recover_interrupted=True, observer=None):
        self.settings = settings
        self.observer = observer
        self.locks = {}
        self.threads = {}
        self.mutex = threading.Lock()
        live_roots = [Path(e['root']) for e in settings['servers'].values()]
        stage_roots = [Path(e['staging']) for e in settings['servers'].values()]
        for roots in (live_roots, stage_roots):
            for i, first in enumerate(roots):
                for second in roots[i + 1:]:
                    if first == second or first in second.parents or second in first.parents:
                        raise ValueError('Server scopes must have separate, non-overlapping roots')
        for live in live_roots:
            for stage in stage_roots:
                if live == stage or live in stage.parents or stage in live.parents:
                    raise ValueError('Staging must be outside every configured server volume')
        for server_id, entry in settings['servers'].items():
            uuid.UUID(server_id)
            additional_directories(entry.get('additional_managed_directories', []))
            wrappers = additional_directories(entry.get('archive_roots', []))
            if set(wrappers) & (set(entry.get('managed_directories', MANAGED_DIRS)) | set(entry.get('additional_managed_directories', []))):
                raise ValueError('Archive wrappers cannot also be managed directories')
            for option in ('managed_directories', 'excluded_directories'):
                values = entry.get(option, [])
                if not isinstance(values, list) or len(values) > 128:
                    raise ValueError('Inventory directories must be a bounded list')
                for value in values:
                    relative(value)
                    if '/' in value:
                        raise ValueError('Inventory directories must be top-level names')
            if require_tokens and len(entry.get('token', '')) < 32:
                raise ValueError('Use independent random per-server tokens of at least 32 characters')
            live, stage = Path(entry['root']), Path(entry['staging'])
            for path in (live, stage):
                if not path.is_absolute() or not path.is_dir() or path.resolve() != path:
                    raise ValueError('Server and staging roots must be canonical existing directories without symlinks')
            if live == stage or live in stage.parents or stage in live.parents:
                raise ValueError('Staging must be outside the Wings volume')
            # Only worker-owned paths may be writable by this service account.
            if stage.stat().st_mode & 0o077:
                raise ValueError('Staging directory must have mode 0700')
            self.locks[server_id] = threading.Lock()
            # Interrupted requests are retained, never replayed on restart.
            if recover_interrupted:
                self.recover(server_id)

    def recover(self, server):
        for path in Path(self.entry(server)['staging']).glob('*/operation.json'):
            data = json.loads(path.read_text())
            if data['state'] not in ('completed', 'failed', 'cancelled', 'interrupted', 'awaiting_resolution'):
                data.update(state='interrupted', error=FAILURES['INTERRUPTED'][0], diagnostic=diagnostic(PreparationFailure('INTERRUPTED'), data.get('progress', {}).get('phase', data.get('state'))))
                atomic_json(path, data)

    def entry(self, server):
        return self.settings['servers'][server]

    def world_roots(self, server):
        result = set(RUNTIME) | set(self.entry(server).get('excluded_directories', DEFAULT_EXCLUDED))
        try:
            with open_regular(self.entry(server)['root'], 'server.properties') as source:
                raw = source.read(1048577)
                if len(raw) > 1048576:
                    raise ValueError('server.properties exceeds read limit')
                for line in raw.decode('utf-8', 'strict').splitlines():
                    line = line.strip()
                    if not line or line.startswith(('#', '!')):
                        continue
                    key = re.split(r'[=:\s]', line, maxsplit=1)[0]
                    if '\\' in key:
                        raise ValueError('Escaped property keys prevent safe world detection')
                    match = re.match(r'^level-name(?:\s*[=:]\s*|\s+)(.*)$', line)
                    if match:
                        name = match[1].strip()
                        relative(name)
                        first = name.split('/')[0]
                        result.update((first, first + '_nether', first + '_the_end'))
        except FileNotFoundError:
            pass
        return result

    def managed(self, server, path, excluded=None):
        relative(path)
        markers = ('region', 'playerdata', 'entities', 'poi', 'level.dat', 'level.dat_old', 'session.lock')
        if any(p in markers or re.fullmatch(r'DIM-?[0-9]+', p) for p in path.split('/')):
            return False
        allowed = self.managed_directories(server)
        if path.split('/')[0] in self.entry(server).get('additional_managed_directories', []) and any(
                p.lower() in ('logs', 'crash-reports', 'backups', 'backup') for p in path.split('/')):
            return False
        return not any(path == r or path.startswith(r + '/') for r in (self.world_roots(server) if excluded is None else excluded)) and not path.startswith('.arcturus-modpack-manager') and (len(path.split('/')) == 1 or path.split('/')[0] in allowed)

    def managed_directories(self, server):
        entry = self.entry(server)
        return set(entry.get('managed_directories', MANAGED_DIRS)) | set(additional_directories(entry.get('additional_managed_directories', [])))

    def inventory(self, server, operation_id=None):
        if operation_id is None:
            return self._inventory(server)
        base = self.operation_path(server, operation_id)
        update = ProgressState(base / 'comparison.json', {'operation_id': operation_id, 'state': 'comparing'}, self.observer)
        cancelled = lambda: (base / 'cancel').exists()
        update(state='comparing', step='inventory_enumeration', files_done=0, files_total=None)
        try:
            result = self._inventory(server, update, cancelled)
            update(force=True, state='completed', phase='comparing', files_done=len(result['files']), files_total=len(result['files']))
            return result
        except InterruptedError:
            update(force=True, state='cancelled', diagnostic=diagnostic(PreparationFailure('CANCELLED'), 'comparing'), error=FAILURES['CANCELLED'][0])
            raise
        except Exception as error:
            update.failed(error)
            raise

    def _inventory(self, server, report=lambda **v: None, cancelled=lambda: False):
        entry = self.entry(server)
        root = Path(entry['root'])
        paths = []
        entries_scanned = 0
        excluded = sorted(self.world_roots(server))
        for item in root.iterdir():
            if not self.managed(server, item.name, excluded):
                if item.is_dir():
                    excluded.append(item.name)
                continue
            if item.is_symlink():
                raise ValueError('Symlink in managed inventory')
            if item.is_dir():
                if item.name not in self.managed_directories(server):
                    excluded.append(item.name)
                    continue
                for directory, dirs, names in os.walk(item, followlinks=False, onerror=fail_walk):
                    if cancelled():
                        raise InterruptedError('Operation cancelled')
                    entries_scanned += len(dirs) + len(names)
                    report(state='comparing', step='inventory_enumeration', entries_scanned=entries_scanned)
                    if entries_scanned > MAX_FILES or len(Path(directory).relative_to(root).parts) > 32:
                        raise ValueError('Managed inventory directory/count limit exceeded')
                    for name in dirs + names:
                        path = Path(directory) / name
                        if path.is_symlink():
                            raise ValueError('Symlink in managed inventory')
                    safe_dirs = []
                    for name in dirs:
                        rel = str((Path(directory) / name).relative_to(root))
                        if self.managed(server, rel, excluded):
                            safe_dirs.append(name)
                        else:
                            excluded.append(rel)
                    dirs[:] = safe_dirs
                    for name in names:
                        rel = str((Path(directory) / name).relative_to(root))
                        if self.managed(server, rel, excluded):
                            paths.append(rel)
                    if len(paths) > MAX_FILES:
                        raise ValueError('Inventory file limit exceeded')
            else:
                paths.append(item.name)
        hashed = self.hashes(server, paths, report=report, cancelled=cancelled)
        if hashed['missing']:
            raise PreparationFailure('FILES_CHANGED')
        files = hashed['files']
        identities, metadata = {}, {}
        report(state='comparing', step='reading_mod_metadata', files_done=0, files_total=len(paths))
        for index, path in enumerate(paths):
            if cancelled():
                raise InterruptedError('Operation cancelled')
            item = mod_metadata(root, path)
            if item:
                identities[path], metadata[path] = item['identity'], item
            report(state='comparing', files_done=index + 1)
        return {'complete': True, 'files': files, 'identities': identities, 'metadata': metadata,
                'scope': 'managed-pack-files', 'excluded_roots': sorted(set(excluded)),
                'notice': 'World data is not hashed. A running-server inventory is a point-in-time observation.'}

    def hashes(self, server, paths, report=lambda **v: None, cancelled=lambda: False):
        if not isinstance(paths, list) or len(paths) > MAX_FILES or len(set(paths)) != len(paths):
            raise ValueError('Invalid inventory path list')
        result, missing = {}, []
        budget = self.entry(server).get('max_inventory_bytes', 32 * 1024**3)
        excluded = self.world_roots(server)
        consumed = 0
        report(state='comparing', step='inventory_hashing', files_done=0, files_total=len(paths), bytes_done=0, bytes_total=None)
        for index, path in enumerate(paths):
            if cancelled():
                raise InterruptedError('Operation cancelled')
            if not self.managed(server, path, excluded):
                raise ValueError('Hash request is outside managed pack scope; world reads are forbidden')
            try:
                report(**{'file': None, 'file_id': None, **safe_file(path)})
                digest, size = hash_file(self.entry(server)['root'], path, budget,
                    progress=lambda n: report(bytes_done=consumed + n), cancelled=cancelled)
                consumed += size
                budget -= size
                result[path] = digest
            except FileNotFoundError:
                missing.append(path)
            report(files_done=index + 1, bytes_done=consumed)
        return {'files': result, 'missing': missing}

    def operation_path(self, server, operation_id):
        if not isinstance(operation_id, str) or not re.fullmatch('[a-f0-9]{32}', operation_id):
            raise ValueError('Invalid operation ID')
        return Path(self.entry(server)['staging']) / operation_id

    def status(self, server, operation_id):
        return json.loads((self.operation_path(server, operation_id) / 'operation.json').read_text())

    def prepare(self, server, recipe, operation_id):
        base = self.operation_path(server, operation_id)
        # Idempotent submission: the exact payload is pinned before work starts.
        fingerprint = hashlib.sha256(json.dumps(recipe, sort_keys=True).encode()).hexdigest()
        with self.mutex:
            if base.exists():
                data = self.status(server, operation_id)
                if data.get('recipe_sha256') != fingerprint:
                    raise ValueError('Operation ID already used for a different release')
                return data
            base.mkdir(mode=0o700)
            data = {'operation_id': operation_id, 'state': 'queued', 'recipe_sha256': fingerprint,
                    'bytes_downloaded': 0, 'files_verified': 0, 'created_at': time.time()}
            atomic_json(base / 'operation.json', data)
            thread = threading.Thread(target=self._prepare, args=(server, base, recipe), daemon=True)
            self.threads[(server, operation_id)] = thread
            thread.start()
        return data

    def _prepare(self, server, base, recipe):
        path = base / 'operation.json'
        data = json.loads(path.read_text())
        update = ProgressState(path, data, self.observer)
        cancelled = lambda: (base / 'cancel').exists()
        try:
            # One preparation per server bounds disk/CPU use and prevents resource exhaustion.
            if not self.locks[server].acquire(blocking=False):
                raise ValueError('Another node operation is active for this server')
            try:
                entry = self.entry(server)
                maximum = min(entry.get('max_archive_bytes', 8 * 1024**3), recipe.get('limits', {}).get('max_archive_bytes', 8 * 1024**3))
                files = base / 'files'
                files.mkdir(mode=0o700)
                if recipe.get('blockers') or not recipe.get('version') or not recipe.get('project') or not recipe.get('provider'):
                    raise PreparationFailure('METADATA_INVALID')
                items = recipe.get('downloads', [])
                if not items or len(items) > MAX_FILES:
                    raise PreparationFailure('METADATA_INVALID')
                total = 0
                update(download_total_bytes=sum(item['size'] for item in items) if all(type(item.get('size')) is int for item in items) else None)
                artifacts = []
                for i, item in enumerate(items):
                    if cancelled():
                        raise InterruptedError('Operation cancelled')
                    update(state='downloading', current_file=i + 1, total_files=len(items))
                    dest = base / ('download-%d' % i)
                    if 'artifact_id' in item:
                        sha, size = local_artifact(item['artifact_id'], dest, entry, item['hashes'], maximum - total, report=lambda **v: transfer_report(update, total, **v), cancelled=cancelled)
                        if item.get('size') is not None and item['size'] != size:
                            raise PreparationFailure('TRANSFER_SIZE')
                    else:
                        sha, size = fetch(item['url'], dest, entry.get('allowed_hosts', []), item['hashes'],
                                          maximum - total, item.get('size'),
                                          lambda n: update(bytes_downloaded=total + n), cancelled, report=lambda **v: transfer_report(update, total, **v))
                    total += size
                    artifacts.append({'sha256': sha, 'bytes': size, 'path': item.get('path'), 'hashes': item['hashes']})
                    if item.get('archive'):
                        if len(items) != 1:
                            raise ValueError('Archive recipes require a single pinned artifact')
                        update(state='extracting')
                        unpack = base / 'unpacked'
                        unpack.mkdir(mode=0o700)
                        extract_zip(dest, unpack, maximum, cancelled, report=update)
                        if recipe.get('format') == 'curseforge':
                            manifest_path = unpack / 'manifest.json'
                            if manifest_path.stat().st_size > MAX_REQUEST:
                                raise ValueError('CurseForge manifest exceeds metadata limit')
                            manifest = json.loads(manifest_path.read_text())
                            self.curseforge_requirements(manifest)
                            atomic_json(base / 'requirements.json', manifest)
                            atomic_json(base / 'input.json', {'recipe': recipe, 'artifacts': artifacts, 'bytes': total})
                            update(state='awaiting_resolution', requirements_sha256=hashlib.sha256((base / 'requirements.json').read_bytes()).hexdigest())
                            return
                        if recipe.get('format') == 'mrpack':
                            recipe['runtime'] = {**recipe.get('runtime', {}), **self._mrpack(server, unpack, files, maximum, cancelled, update)}
                        else:
                            children = list(unpack.iterdir())
                            content = children[0] if len(children) == 1 and children[0].is_dir() and children[0].name in entry.get('archive_roots', []) and self.managed(server, children[0].name) else unpack
                            # A manifest-only CurseForge pack must never be mistaken for a ready server.
                            if (content / 'manifest.json').exists():
                                manifest = json.loads((content / 'manifest.json').read_text())
                                if manifest.get('manifestType') == 'minecraftModpack':
                                    raise ValueError('CurseForge client manifest requires complete API file resolution; select an official server pack')
                            for child in content.iterdir():
                                os.rename(child, files / child.name)
                    else:
                        target = files / relative(item['path'])
                        target.parent.mkdir(parents=True, exist_ok=True)
                        os.rename(dest, target)
                    update(files_verified=i + 1)
                self._finalize(server, base, recipe, artifacts, maximum, update)
            finally:
                self.locks[server].release()
        except InterruptedError:
            update(force=True, state='cancelled', error=FAILURES['CANCELLED'][0], diagnostic=diagnostic(PreparationFailure('CANCELLED'), data.get('progress', {}).get('phase')))
        except Exception as error:
            # Never log signed URLs, request payloads, or credentials.
            update.failed(error, artifact_index=data.get('current_file'), **{key: data.get('progress', {}).get(key) for key in ('file', 'file_id')})

    def _finalize(self, server, base, recipe, artifacts, maximum, update):
        files = base / 'files'
        update(state='hashing')
        excluded = self.world_roots(server)
        unknown = [child.name for child in files.iterdir() if child.is_dir() and child.name not in self.managed_directories(server)]
        if unknown:
            protected = any(not self.managed(server, name, excluded) for name in unknown)
            raise ManagedPathFailure(unknown, protected=protected)
        manifest, identities, metadata, bytes_hashed = {}, {}, {}, 0
        planned, total_bytes = [], 0
        cancelled = lambda: (base / 'cancel').exists()
        for directory, dirs, names in os.walk(files, onerror=fail_walk):
            for name in names:
                rel = str((Path(directory) / name).relative_to(files))
                if not self.managed(server, rel):
                    raise ManagedPathFailure([rel], protected=True)
                info = (files / rel).lstat()
                if not stat.S_ISREG(info.st_mode):
                    raise PreparationFailure('MANAGED_PATH', file=rel)
                planned.append((rel, info.st_size)); total_bytes += info.st_size
                if len(planned) > MAX_FILES or total_bytes > maximum:
                    raise PreparationFailure('INVENTORY_LIMIT', file=rel)
        update(state='hashing', bytes_done=0, bytes_total=total_bytes, files_done=0, files_total=len(planned))
        for index, (rel, _) in enumerate(planned):
            update(**{'file': None, 'file_id': None, **safe_file(rel)})
            digest, size = hash_file(files, rel, maximum - bytes_hashed,
                progress=lambda n: update(bytes_done=bytes_hashed + n), cancelled=cancelled)
            bytes_hashed += size
            manifest[rel] = digest
            item = mod_metadata(files, rel)
            if item:
                identities[rel], metadata[rel] = item['identity'], item
            update(bytes_done=bytes_hashed, files_done=index + 1)
        if not manifest:
            raise ValueError('Prepared server pack is empty')
        result = {'schema': 2, 'provider': recipe['provider'], 'project': recipe['project'],
                  'version': recipe['version'], 'files': manifest, 'identities': identities, 'metadata': metadata,
                  'runtime': recipe.get('runtime', {}), 'artifacts': artifacts,
                  'managed_directories': sorted(self.managed_directories(server)),
                  'verification': 'upstream-checksums', 'bytes': bytes_hashed}
        atomic_json(base / 'manifest.json', result)
        update(state='completed', manifest_sha256=hashlib.sha256((base / 'manifest.json').read_bytes()).hexdigest())

    def curseforge_requirements(self, manifest):
        if (not isinstance(manifest, dict) or manifest.get('manifestType') != 'minecraftModpack'
                or manifest.get('manifestVersion') != 1 or not isinstance(manifest.get('files'), list)
                or len(manifest['files']) > 20000):
            raise ValueError('Unsupported CurseForge manifest')
        requirements = {}
        for item in manifest['files']:
            project, file_id = str(item.get('projectID', '')), str(item.get('fileID', ''))
            if (item.get('required') is not True or not re.fullmatch('[1-9][0-9]{0,10}', project)
                    or not re.fullmatch('[1-9][0-9]{0,10}', file_id) or project in requirements):
                raise ValueError('Optional, duplicated or invalid CurseForge requirement needs explicit selection')
            requirements[project] = file_id
        overrides = relative(manifest.get('overrides', 'overrides'))
        if overrides.split('/')[0] in RUNTIME:
            raise ValueError('Unsafe CurseForge overrides directory')
        return requirements

    def requirements(self, server, operation_id):
        status = self.status(server, operation_id)
        if status['state'] != 'awaiting_resolution':
            raise ValueError('Operation is not waiting for required-file resolution')
        path = self.operation_path(server, operation_id) / 'requirements.json'
        digest = hashlib.sha256(path.read_bytes()).hexdigest()
        if digest != status['requirements_sha256']:
            raise ValueError('CurseForge requirements integrity changed')
        return {'manifest': json.loads(path.read_text()), 'sha256': digest}

    def resolve(self, server, operation_id, requirements_sha256, downloads):
        base = self.operation_path(server, operation_id)
        fingerprint = hashlib.sha256(json.dumps({'requirements': requirements_sha256, 'files': downloads}, sort_keys=True).encode()).hexdigest()
        with self.mutex:
            data = self.status(server, operation_id)
            if 'resolution_sha256' in data:
                if data['resolution_sha256'] != fingerprint:
                    raise ValueError('Required-file resolution is already pinned to another payload')
                return data
            requirements = self.requirements(server, operation_id)
            if requirements['sha256'] != requirements_sha256:
                raise ValueError('Required-file resolution refers to another archive manifest')
            expected = self.curseforge_requirements(requirements['manifest'])
            if not isinstance(downloads, list) or len(downloads) != len(expected):
                raise ValueError('Required-file resolution is incomplete')
            actual, paths = {}, set()
            for item in downloads:
                project, file_id = str(item.get('project_id', '')), str(item.get('file_id', ''))
                path = relative(item.get('path'))
                if (project in actual or path in paths or not path.startswith('mods/') or '/' in path[5:]
                        or not path.lower().endswith('.jar') or not item.get('hashes') or item.get('archive')):
                    raise ValueError('Invalid or duplicate required-file download')
                actual[project] = file_id
                paths.add(path)
            if actual != expected:
                raise ValueError('Resolved downloads do not exactly match required project/file IDs')
            atomic_json(base / 'resolution.json', downloads)
            data.update(state='downloading', resolution_sha256=fingerprint, files_verified=0, total_files=len(downloads), updated_at=time.time())
            atomic_json(base / 'operation.json', data)
            thread = threading.Thread(target=self._resolve, args=(server, base, downloads), daemon=True)
            self.threads[(server, operation_id)] = thread
            thread.start()
            return data

    def _resolve(self, server, base, downloads):
        path = base / 'operation.json'
        data = json.loads(path.read_text())
        update = ProgressState(path, data, self.observer)
        cancelled = lambda: (base / 'cancel').exists()
        acquired = False
        try:
            acquired = self.locks[server].acquire(timeout=2)
            if not acquired:
                raise ValueError('Another node operation is active for this server')
            entry = self.entry(server)
            saved = json.loads((base / 'input.json').read_text())
            recipe, artifacts, total = saved['recipe'], saved['artifacts'], saved['bytes']
            maximum = min(entry.get('max_archive_bytes', 8 * 1024**3), recipe.get('limits', {}).get('max_archive_bytes', 8 * 1024**3))
            files = base / 'files'
            update(download_total_bytes=total + sum(item['size'] for item in downloads) if all(type(item.get('size')) is int for item in downloads) else None)
            for index, item in enumerate(downloads):
                if cancelled():
                    raise InterruptedError('Operation cancelled')
                update(state='downloading', current_file=index + 1, total_files=len(downloads))
                target = files / relative(item['path'])
                target.parent.mkdir(parents=True, exist_ok=True)
                # No artifact can become visible in a completed manifest before its hash is verified.
                sha, size = fetch(item['url'], target, entry.get('allowed_hosts', []), item['hashes'], maximum - total,
                                  item.get('size'), lambda n: update(bytes_downloaded=total + n), cancelled, report=lambda **v: transfer_report(update, total, **v))
                total += size
                artifacts.append({'sha256': sha, 'bytes': size, 'path': item['path'], 'hashes': item['hashes'],
                                  'project_id': item['project_id'], 'file_id': item['file_id']})
                update(files_verified=index + 1)
            update(state='extracting')
            manifest = json.loads((base / 'requirements.json').read_text())
            overrides = base / 'unpacked' / relative(manifest.get('overrides', 'overrides'))
            if overrides.exists():
                for directory, dirs, names in os.walk(overrides):
                    for name in names:
                        source = Path(directory) / name
                        rel = relative(str(source.relative_to(overrides)))
                        if not self.managed(server, rel):
                            raise ValueError('Unsafe CurseForge override path')
                        target = files / rel
                        target.parent.mkdir(parents=True, exist_ok=True)
                        os.replace(source, target)
            runtime = {'runtime_prepared': False, 'minecraft': manifest.get('minecraft', {}).get('version')}
            loaders = manifest.get('minecraft', {}).get('modLoaders', [])
            chosen = [loader for loader in loaders if loader.get('primary') is True]
            if len(chosen) == 1:
                loader_id = chosen[0].get('id', '')
                for loader in ('neoforge', 'forge', 'fabric', 'quilt'):
                    if loader_id.startswith(loader + '-'):
                        runtime.update(loader=loader, loader_version=loader_id[len(loader) + 1:])
                        break
            recipe['runtime'] = runtime
            self._finalize(server, base, recipe, artifacts, maximum, update)
        except InterruptedError:
            update(force=True, state='cancelled', error=FAILURES['CANCELLED'][0], diagnostic=diagnostic(PreparationFailure('CANCELLED'), data.get('progress', {}).get('phase')))
        except Exception as error:
            update.failed(error, artifact_index=data.get('current_file'), **{key: data.get('progress', {}).get(key) for key in ('file', 'file_id')})
        finally:
            if acquired:
                self.locks[server].release()

    def _mrpack(self, server, unpack, files, maximum, cancelled, update):
        index_path = unpack / 'modrinth.index.json'
        if index_path.stat().st_size > MAX_REQUEST:
            raise ValueError('Modrinth index is too large')
        index = json.loads(index_path.read_text())
        if index.get('formatVersion') != 1 or index.get('game') != 'minecraft' or not isinstance(index.get('files'), list):
            raise ValueError('Unsupported Modrinth index')
        total = 0
        base_offset = update.data.get('bytes_downloaded', 0) if isinstance(update, ProgressState) else 0
        update(download_total_bytes=None)
        seen = set()
        for item in index['files']:
            if item.get('env', {}).get('server', 'required') not in ('required', 'unsupported', 'optional'):
                raise ValueError('Invalid Modrinth server environment requirement')
            if item.get('env', {}).get('server') == 'unsupported':
                continue
            # Optional files need an administrator decision; never silently choose.
            if item.get('env', {}).get('server') == 'optional':
                raise ValueError('Optional server mod requires an explicit normalized selection')
            path = relative(item['path'])
            if path in seen or not self.managed(server, path):
                raise ValueError('Duplicate or unsafe Modrinth path')
            seen.add(path)
            dest = files / path
            dest.parent.mkdir(parents=True, exist_ok=True)
            urls = item.get('downloads', [])
            if not urls:
                raise ValueError('Required Modrinth file has no download')
            update(state='downloading', current_file=len(seen), total_files=len(index['files']))
            _, size = fetch(urls[0], dest, self.entry(server).get('allowed_hosts', []),
                            item.get('hashes', {}), maximum - total, item.get('fileSize'), cancelled=cancelled, report=lambda **v: transfer_report(update, total, **v))
            total += size
            update(files_verified=len(seen))
        # server-overrides take precedence; client-overrides are never used.
        for folder in ('overrides', 'server-overrides'):
            src = unpack / folder
            if not src.exists():
                continue
            for directory, dirs, names in os.walk(src):
                for name in names:
                    origin = Path(directory) / name
                    path = relative(str(origin.relative_to(src)))
                    if not self.managed(server, path):
                        raise ValueError('Unsafe Modrinth override')
                    dest = files / path
                    dest.parent.mkdir(parents=True, exist_ok=True)
                    os.replace(origin, dest)
        runtime = {'runtime_prepared': False}
        dependencies = index.get('dependencies', {})
        runtime['minecraft'] = dependencies.get('minecraft')
        for key in ('forge', 'neoforge', 'fabric-loader', 'quilt-loader'):
            if key in dependencies:
                runtime['loader'] = key.replace('-loader', '')
                runtime['loader_version'] = dependencies[key]
        return runtime

    def handle(self, server, operation, payload):
        payload = payload or {}  # PHP serializes an empty request array as [].
        if operation == 'capabilities':
            return {'protocol': 1, 'hashes': True, 'inventory': True, 'prepare': True, 'curseforge_resolution': True,
                    'deploy': False, 'preserve_exclusions': False,
                    'reason': 'Remote destructive transactions and restore verification require a disposable Pelican/Wings integration qualification'}
        if operation == 'hashes':
            return self.hashes(server, payload['paths'])
        if operation == 'inventory':
            return self.inventory(server, payload.get('operation_id'))
        if operation == 'prepare':
            return self.prepare(server, payload['recipe'], payload['operation_id'])
        if operation == 'requirements':
            return self.requirements(server, payload['operation_id'])
        if operation == 'resolve':
            return self.resolve(server, payload['operation_id'], payload['requirements_sha256'], payload['files'])
        if operation == 'status':
            return self.status(server, payload['operation_id'])
        if operation == 'cancel':
            base = self.operation_path(server, payload['operation_id'])
            data = self.status(server, payload['operation_id'])
            comparison = base / 'comparison.json'
            comparing = comparison.exists() and json.loads(comparison.read_text()).get('state') == 'comparing'
            if data['state'] not in ('completed', 'failed', 'cancelled', 'interrupted') or comparing:
                (base / 'cancel').touch(mode=0o600)
                if data['state'] == 'awaiting_resolution':
                    data.update(state='cancelled', error='Preparation cancelled before required-file resolution')
                    atomic_json(base / 'operation.json', data)
            return data
        if operation == 'stage_file':
            data = self.status(server, payload['operation_id'])
            if data['state'] != 'completed':
                raise ValueError('Stage is incomplete')
            base = self.operation_path(server, payload['operation_id'])
            manifest_path = base / 'manifest.json'
            if hashlib.sha256(manifest_path.read_bytes()).hexdigest() != data['manifest_sha256']:
                raise ValueError('Prepared manifest integrity changed')
            manifest = json.loads(manifest_path.read_text())
            path = relative(payload['path'])
            if not path.endswith('.properties') or path not in manifest['files']:
                raise ValueError('Only verified staged properties files can be read for merging')
            with open_regular(base / 'files', path) as source:
                content = source.read(1048577)
            if len(content) > 1048576 or hashlib.sha256(content).hexdigest() != manifest['files'][path]:
                raise ValueError('Staged configuration size or checksum mismatch')
            return {'content': content.decode('utf-8'), 'sha256': manifest['files'][path]}
        if operation == 'manifest':
            data = self.status(server, payload['operation_id'])
            if data['state'] != 'completed':
                raise ValueError('Preparation has not completed')
            path = self.operation_path(server, payload['operation_id']) / 'manifest.json'
            if hashlib.sha256(path.read_bytes()).hexdigest() != data['manifest_sha256']:
                raise ValueError('Prepared manifest integrity changed')
            return json.loads(path.read_text())
        raise ValueError('Unsupported capability; no files changed')


class BoundedHTTPServer(ThreadingHTTPServer):
    daemon_threads = True
    def __init__(self, *args, **kwargs):
        self.slots = threading.BoundedSemaphore(8)
        super().__init__(*args, **kwargs)
    def process_request(self, request, client_address):
        if not self.slots.acquire(blocking=False):
            request.close()
            return
        try:
            super().process_request(request, client_address)
        except BaseException:
            self.slots.release()
            raise
    def process_request_thread(self, request, client_address):
        try:
            super().process_request_thread(request, client_address)
        finally:
            self.slots.release()


class Handler(BaseHTTPRequestHandler):
    server_version = 'ArcturusNode/1'
    def setup(self):
        super().setup()
        self.connection.settimeout(30)
    def log_message(self, fmt, *args):
        pass  # Default logs contain paths; operators should log only HTTP status at the TLS proxy.
    def do_POST(self):
        try:
            match = re.fullmatch(r'/v1/servers/([a-f0-9-]{36})/([a-z_]+)', self.path)
            if not match:
                self.send_error(404)
                return
            server, operation = match.groups()
            entry = self.server.worker.settings['servers'].get(server)
            expected = 'Bearer ' + (entry or {}).get('token', '')
            if not entry or not hmac.compare_digest(self.headers.get('Authorization', ''), expected):
                self.send_error(403)
                return
            length = int(self.headers.get('Content-Length', '-1'))
            if not 0 <= length <= MAX_REQUEST:
                self.send_error(413)
                return
            payload = json.loads(self.rfile.read(length))
            result = self.server.worker.handle(server, operation, payload)
            body = json.dumps(result).encode()
            self.send_response(200)
            self.send_header('Content-Type', 'application/json')
            self.send_header('Content-Length', str(len(body)))
            self.end_headers()
            self.wfile.write(body)
        except (ValueError, KeyError, TypeError, OSError, zipfile.BadZipFile) as error:
            body = json.dumps({'diagnostic': diagnostic(error, 'comparing' if operation == 'inventory' else 'queued')}).encode()
            self.send_response(422)
            self.send_header('Content-Length', str(len(body)))
            self.end_headers()
            self.wfile.write(body)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--config', required=True)
    parser.add_argument('--port', type=int, default=9876)
    args = parser.parse_args()
    config = Path(args.config)
    if config.stat().st_mode & 0o077:
        raise ValueError('Configuration containing secrets must have mode 0600')
    settings = json.loads(config.read_text())
    # Bind only to loopback. A TLS reverse proxy with a Panel-only firewall is required remotely.
    httpd = BoundedHTTPServer(('127.0.0.1', args.port), Handler)
    httpd.worker = Worker(settings)
    httpd.serve_forever()


if __name__ == '__main__':
    main()
