#!/usr/bin/env python3
"""Administrator-only, one-time Ubuntu ACL provisioning. Never called by PHP/web requests."""
import argparse
import json
import os
from pathlib import Path
import pwd
import re
import stat
import subprocess
import sys
import tempfile

CONFIG = Path('/etc/arcturus-modpack-manager/local-node.json')
STAGING = Path('/var/lib/arcturus-modpack-manager/staging')
# Privileged provisioning has a fixed upper scope. No executable/plugin imports under root.
PACK_DIRS = frozenset(('mods', 'config', 'configs', 'scripts', 'kubejs', 'libraries', 'defaultconfigs',
    'configureddefaults', 'plugins', 'resources', 'patches', 'datapacks', 'ftbteambases', 'resourcepacks',
    'shaderpacks', 'global_packs', 'paxi', 'openloader', 'schematics', 'assets', 'structures'))
PROTECTED = frozenset(('world', 'world_nether', 'world_the_end', 'saves', 'dimensions', 'DIM-1', 'DIM1',
    'logs', 'crash-reports', 'backups', 'backup', 'data', 'region', 'playerdata', 'entities', 'poi',
    'level.dat', 'level.dat_old', 'session.lock'))
WORLD_MARKERS = frozenset(('region', 'playerdata', 'entities', 'poi', 'level.dat', 'level.dat_old', 'session.lock'))
HOSTS = ['github.com', 'release-assets.githubusercontent.com', 'objects.githubusercontent.com',
    'cdn.modrinth.com', 'files.feed-the-beast.com', 'edge.forgecdn.net', 'mediafilez.forgecdn.net',
    'download.nodecdn.net']
UUID = re.compile(r'[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}')


def approved_additions(values):
    if not isinstance(values, list) or len(values) > 128:
        raise ValueError('Additional managed directories must be a bounded list')
    for value in values:
        if (not isinstance(value, str) or not re.fullmatch(r'[A-Za-z0-9][A-Za-z0-9_.-]{0,63}', value)
                or value.lower() in {s.lower() for s in PROTECTED} or re.fullmatch(r'DIM-?[0-9]+', value, re.I)):
            raise ValueError('Additional managed directory is unsafe or reserved for runtime data')
    return sorted(set(values))


def canonical(value, existing=True):
    path = Path(value)
    if not path.is_absolute() or path == Path('/') or str(path) != value or path.resolve() != path:
        raise ValueError('Use canonical absolute paths without symlinks or traversal')
    if existing and not path.is_dir():
        raise ValueError('Approved directory does not exist')
    return path


def overlap(first, second):
    return first == second or first in second.parents or second in first.parents


def perms(value):
    return {c for c in value if c in 'rwx'}


def spelling(value):
    return ''.join(c if c in value else '-' for c in 'rwx')


def acl_updates(text, uid, directory, rights=None, defaults=True):
    """Keep other ACL subjects' effective permissions unchanged when expanding a mask."""
    entries = {}
    for line in text.splitlines():
        line = line.split('#', 1)[0].strip()
        if not line:
            continue
        parts = line.split(':')
        key, value = ':'.join(parts[:-1]), parts[-1].strip()
        entries[key] = perms(value)
    updates = {}
    target = rights or ('r-x' if directory else 'r--')
    for prefix in (['', 'default:'] if directory and defaults else ['']):
        available = {k[len(prefix):]: v for k, v in entries.items() if k.startswith(prefix) and
            (prefix or not k.startswith('default:'))}
        if not available:
            available = {k: v for k, v in entries.items() if not k.startswith('default:')}
        mask = available.get('mask:', available.get('group:', set()))
        for key, value in available.items():
            if key in ('user:', 'other:'):
                updates[prefix + key] = value
            elif key == 'group:' or key.startswith('group:') or (key.startswith('user:') and key != 'user:'):
                updates[prefix + key] = value & mask
        updates[prefix + 'user:' + str(uid)] = perms(target)
        updates[prefix + 'mask:'] = mask | perms(target)
    return ','.join(key + ':' + spelling(value) for key, value in updates.items())


def grant(fd, uid, directory, *, traversal=False):
    info = os.fstat(fd)
    if info.st_uid == uid and info.st_mode & stat.S_IWUSR:
        raise ValueError('Panel account owns a writable volume/ancestor. Use separate Panel and Wings owners; ownership is never changed automatically')
    name = '/proc/self/fd/' + str(fd)
    current = subprocess.run(['/usr/bin/getfacl', '-c', '-n', '-p', '--', name], pass_fds=(fd,),
        check=True, capture_output=True, text=True).stdout
    update = acl_updates(current, uid, directory, rights='--x' if traversal else None, defaults=not traversal)
    subprocess.run(['/usr/bin/setfacl', '-m', update, '--', name], pass_fds=(fd,),
        check=True, capture_output=True)


def world_roots(fd):
    excluded = set(PROTECTED)
    try:
        prop = os.open('server.properties', os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK, dir_fd=fd)
    except FileNotFoundError:
        return excluded
    try:
        if not stat.S_ISREG(os.fstat(prop).st_mode):
            raise ValueError('server.properties must be a regular file')
        raw = os.read(prop, 1048577)
        if len(raw) > 1048576:
            raise ValueError('server.properties exceeds read limit')
        for line in raw.decode('utf-8').splitlines():
            line = line.strip()
            if not line or line.startswith(('#', '!')):
                continue
            key = re.split(r'[=:\s]', line, maxsplit=1)[0]
            if '\\' in key:
                raise ValueError('Escaped properties keys require manual world-path verification')
            match = re.match(r'^level-name(?:\s*[=:]\s*|\s+)(.*)$', line)
            if match:
                value = match[1].strip()
                if (not value or value.startswith('/') or '\\' in value or ':' in value or
                        any(s in ('', '.', '..') for s in value.split('/'))):
                    raise ValueError('Unsafe level-name requires manual world-path verification')
                first = value.split('/')[0]
                excluded.update((first, first + '_nether', first + '_the_end'))
    finally:
        os.close(prop)
    return excluded


def provision_volume(fd, uid, additional=None):
    additions = set(approved_additions(additional or []))
    excluded = world_roots(fd)
    grant(fd, uid, True)
    count = 0
    def visit(directory, top=False, depth=0, custom=False):
        nonlocal count
        if depth > 64:
            raise ValueError('Managed directory depth exceeds provisioning limit')
        with os.scandir(directory) as items:
            for item in items:
                name = item.name
                count += 1
                if count > 100000:
                    raise ValueError('Managed entry count exceeds provisioning limit')
                if ((top and name in excluded) or name in WORLD_MARKERS or name.startswith('.arcturus-modpack-manager') or
                        (custom and name.lower() in ('logs', 'crash-reports', 'backups', 'backup')) or
                        re.fullmatch(r'DIM-?[0-9]+', name)):
                    continue
                info = os.stat(name, dir_fd=directory, follow_symlinks=False)
                is_dir = stat.S_ISDIR(info.st_mode)
                if not (is_dir or stat.S_ISREG(info.st_mode)) or (top and is_dir and name not in PACK_DIRS | additions):
                    continue
                if not is_dir and info.st_nlink != 1:
                    raise ValueError('Hard-linked pack files require independently reviewed permissions; no ACL is granted to shared inodes')
                flags = os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK | (os.O_DIRECTORY if is_dir else 0)
                child = os.open(name, flags, dir_fd=directory)
                try:
                    pinned = os.fstat(child)
                    if (pinned.st_dev, pinned.st_ino) != (info.st_dev, info.st_ino):
                        raise ValueError('Volume entry changed during setup; retry when files are stable')
                    if not is_dir and pinned.st_nlink != 1:
                        raise ValueError('File link count changed during setup; no ACL granted')
                    grant(child, uid, is_dir)
                    if is_dir:
                        visit(child, depth=depth + 1, custom=custom or (top and name in additions))
                finally:
                    os.close(child)
    visit(fd, top=True)
    return count


def run_panel(panel, php, account, arguments):
    # Drop privileges BEFORE interpreting any Panel PHP. No shell or sudoers rule.
    result = subprocess.run(['/usr/sbin/runuser', '-u', account.pw_name, '--', str(php),
        str(panel / 'artisan'), *arguments], cwd=panel, check=True, capture_output=True, text=True,
        timeout=120, env={'PATH': '/usr/sbin:/usr/bin:/sbin:/bin', 'HOME': account.pw_dir, 'LANG': 'C.UTF-8'})
    return result.stdout


def approve(snapshot, node_id, root, stage):
    if (snapshot['node_id'] != node_id or snapshot['volume_root'] != str(root) or
            not UUID.fullmatch(snapshot['node_uuid']) or snapshot['queue_driver'] not in ('redis', 'database') or
            snapshot.get('active_operations', 0)):
        raise ValueError('Node identity/root, asynchronous queue, or active-operation check failed. No approval written')
    if overlap(root, stage):
        raise ValueError('Shared staging must be outside all Wings volumes')
    for value in snapshot.get('volume_roots', [str(root)]):
        if overlap(canonical(value, existing=False), stage):
            raise ValueError('Shared staging overlaps another configured Wings volume root')
    servers = snapshot['servers']
    if not isinstance(servers, list) or len(servers) != len(set(servers)) or any(not UUID.fullmatch(s) for s in servers):
        raise ValueError('Invalid Pelican server UUID metadata')
    for server in servers:
        path = root / server
        if path.exists() or path.is_symlink():
            canonical(str(path))
    return servers


def reject_nested_mounts(root, mountinfo):
    for line in mountinfo.splitlines():
        fields = line.split()
        if len(fields) < 5:
            continue
        mount = re.sub(r'\\([0-7]{3})', lambda m: chr(int(m[1], 8)), fields[4])
        if mount.startswith(str(root) + '/'):
            raise ValueError('Nested mounts under the volume root need an independently reviewed permission setup')


def protected_directory(path, uid=0, mode=0o755):
    # Parent chain must be root-controlled; the final staging parent belongs to the service account.
    for parent in reversed(path.parents):
        info = parent.stat()
        if parent.resolve() != parent or info.st_uid != 0 or info.st_mode & 0o022:
            raise ValueError('Setup/config/staging ancestors must be root-owned and not group/world writable')
    if not path.exists():
        path.mkdir(mode=mode)
        os.chown(path, uid, -1)
        os.chmod(path, mode)
    info = path.lstat()
    if not stat.S_ISDIR(info.st_mode) or info.st_uid != uid or info.st_mode & (0o077 if uid else 0o022):
        raise ValueError('Existing setup/staging directory has unsafe ownership, permissions or type')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--panel', default='/var/www/pelican')
    parser.add_argument('--php', default='/usr/bin/php')
    parser.add_argument('--user', default='www-data')
    parser.add_argument('--node', required=True, type=int)
    parser.add_argument('--volume-root', required=True)
    parser.add_argument('--staging-parent', default=str(STAGING))
    parser.add_argument('--additional-managed-directory', action='append', default=[],
        help='Explicitly approve a reviewed top-level pack directory (repeatable; never inferred from archives)')
    parser.add_argument('--archive-root', action='append', default=[], help='Explicitly review a removable single-directory ZIP wrapper (repeatable)')
    parser.add_argument('--dry-run', action='store_true')
    parser.add_argument('--replace', action='store_true', help='Explicitly replace a previous node/root approval (retains old stages/ACLs)')
    args = parser.parse_args()
    if sys.version_info < (3, 11):
        parser.error('Python 3.11+ is required before host provisioning')
    if os.geteuid() != 0:
        parser.error('Run this one-time host setup as root; PHP is always run as the specified unprivileged account')
    os.umask(0o077)
    try:
        account = pwd.getpwnam(args.user)
        if account.pw_uid == 0:
            raise ValueError('The Panel/queue account must not be root')
        panel, root = canonical(args.panel), canonical(args.volume_root)
        stage = canonical(args.staging_parent, existing=False)
        php = Path(args.php).resolve(strict=True)
        if php.stat().st_uid != 0 or php.stat().st_mode & 0o022:
            raise ValueError('PHP executable must be root-owned and not group/world writable')
        snapshot = json.loads(run_panel(panel, php, account, ['arcturus-modpack:describe-local-node', str(args.node)]))
        servers = approve(snapshot, args.node, root, stage)
        if root.stat().st_uid != 0 or root.stat().st_mode & 0o022:
            raise ValueError('Approved volume parent must be root-owned and not group/world writable')
        for parent in root.parents:
            if parent.stat().st_uid != 0 or parent.stat().st_mode & 0o022:
                raise ValueError('Volume ancestors must be root-owned and not group/world writable')
        reject_nested_mounts(root, Path('/proc/self/mountinfo').read_text())
        previous = {}
        if CONFIG.exists() or CONFIG.is_symlink():
            info = CONFIG.lstat()
            if not stat.S_ISREG(info.st_mode) or info.st_uid != 0 or info.st_mode & 0o022:
                raise ValueError('Existing approval file is unsafe; inspect it before setup')
            previous = json.loads(CONFIG.read_text())
        additions = approved_additions(previous.get('additional_managed_directories', []) + args.additional_managed_directory)
        wrappers = approved_additions(previous.get('archive_roots', []) + args.archive_root)
        if set(wrappers) & (PACK_DIRS | set(additions)):
            raise ValueError('Archive wrappers cannot also be managed directories')
        config = {**previous, 'schema': 1, 'node_id': args.node, 'node_uuid': snapshot['node_uuid'], 'uid': account.pw_uid,
            'volume_root': str(root), 'staging_parent': str(stage), 'allowed_hosts': previous.get('allowed_hosts', HOSTS)}
        if additions:
            config['additional_managed_directories'] = additions
        if wrappers:
            config['archive_roots'] = wrappers
        if previous and previous != config and not args.replace:
            raise ValueError('Approval changed; inspect existing operations and explicitly use --replace')
        print('Approve node %s (%s); volume root %s; staging %s; account %s; %s database servers' % (
            args.node, snapshot['node_uuid'], root, stage, account.pw_name, len(servers)))
        print('Explicit additional managed directories: ' + (', '.join(additions) or '(none)'))
        if args.dry_run:
            print('Dry run: no ACLs, files, configuration or queue state changed')
            return 0
        # Fail before ACL changes if required Ubuntu tools are absent.
        for executable in ('/usr/bin/setfacl', '/usr/bin/getfacl', '/usr/sbin/runuser'):
            if not os.access(executable, os.X_OK):
                raise ValueError('Install the Ubuntu acl/util-linux packages before setup')
        protected_directory(CONFIG.parent)
        # Provision one shared parent only; UUID staging is created unprivileged on adoption.
        if not stage.parent.exists():
            protected_directory(stage.parent)
        protected_directory(stage, account.pw_uid, 0o700)
        ancestors = set(root.parents) | set(stage.parents) | {CONFIG.parent}
        for parent in sorted(ancestors, key=lambda path: len(path.parts)):
            accessible = subprocess.run(['/usr/sbin/runuser', '-u', account.pw_name, '--',
                '/usr/bin/test', '-x', str(parent)], cwd='/', capture_output=True)
            if accessible.returncode == 0:
                continue
            fd = os.open(parent, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
            try:
                grant(fd, account.pw_uid, True, traversal=True)
            finally:
                os.close(fd)
        fd = os.open(root, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
        try:
            grant(fd, account.pw_uid, True)  # Default read/traverse ACL for future UUID volumes.
            for server in servers:
                try:
                    volume = os.open(server, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=fd)
                except FileNotFoundError:
                    continue  # Not provisioned by Wings yet; inherited ACL covers future directories.
                try:
                    provision_volume(volume, account.pw_uid, additions)
                finally:
                    os.close(volume)
        finally:
            os.close(fd)
        temp_fd, temp = tempfile.mkstemp(prefix='.local-node-', dir=CONFIG.parent)
        try:
            with os.fdopen(temp_fd, 'w') as out:
                json.dump(config, out, sort_keys=True)
                out.flush(); os.fsync(out.fileno())
                os.fchmod(out.fileno(), 0o644)
            os.replace(temp, CONFIG)
        finally:
            if os.path.exists(temp):
                os.unlink(temp)
        # Laravel exits gracefully between jobs. A fresh boot reads host approval even with config cache.
        run_panel(panel, php, account, ['queue:restart'])
        run_panel(panel, php, account, ['arcturus-modpack:describe-local-node', str(args.node), '--check'])
        print('Host setup complete. Open Local filesystem setup, refresh checks, and enable the default. No live file content was changed.')
        return 0
    except (ValueError, KeyError, TypeError, OSError, subprocess.SubprocessError) as error:
        # Do not dump subprocess stderr, Panel output, credentials or raw configuration.
        print('Setup did not complete. Check node ID/root, service account, canonical protected paths, active operations, Python/PHP and Ubuntu acl tools. Existing ACL changes may remain; rerun the same command after correcting the issue. Use --dry-run to inspect the approved scope.', file=sys.stderr)
        if isinstance(error, ValueError) and not isinstance(error, json.JSONDecodeError):
            print(str(error), file=sys.stderr)
        return 1


if __name__ == '__main__':
    sys.exit(main())
