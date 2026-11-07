<?php

declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use Arcturus\ModpackManager\Services\RestoreInventory;

$root = sys_get_temp_dir().'/arcturus-restore-scope-'.bin2hex(random_bytes(6));
mkdir($root.'/config', 0700, true); mkdir($root.'/private-world/playerdata', 0700, true);
file_put_contents($root.'/server.properties', "level-name=private-world\n");
file_put_contents($root.'/config/managed.cfg', 'original'); file_put_contents($root.'/run.sh', 'original launcher');
// A sparse multi-gigabyte world and inaccessible runtime data must never be opened by verification.
$world = fopen($root.'/private-world/playerdata/large.dat', 'wb'); ftruncate($world, 8 * 1024 ** 3); fclose($world);
chmod($root.'/private-world/playerdata/large.dat', 0000); chmod($root.'/private-world', 0000);
$verifier = new RestoreInventory;
$hash = hash('sha256', 'original'); $launcher = hash('sha256', 'original launcher');
$changes = [['path' => 'config/managed.cfg', 'action' => 'update', 'local_sha256' => $hash],
    ['path' => 'config/new.cfg', 'action' => 'add', 'local_sha256' => null],
    ['path' => 'private-world/playerdata/large.dat', 'action' => 'protected', 'local_sha256' => null]];
try {
    symlink('run.sh', $root.'/unix_args.txt');
    $linked = $verifier->capture($root, $changes, ['run.sh' => $launcher], [], null, ['unix_args.txt' => 'run.sh']);
    unlink($root.'/unix_args.txt'); symlink('config/managed.cfg', $root.'/unix_args.txt');
    rejects(fn () => $verifier->verify($root, $linked), 'Changed launcher symlink accepted during restore verification');
    unlink($root.'/unix_args.txt');
    $receipt = $verifier->capture($root, $changes, ['run.sh' => $launcher]);
    check(count($receipt['hashes']) === 3 && array_key_exists('config/new.cfg', $receipt['hashes']), 'Recovery scope must include mutations, absence and launcher only');
    $verifier->verify($root, $receipt);
    file_put_contents($root.'/config/new.cfg', 'unexpected addition');
    rejects(fn () => $verifier->verify($root, $receipt), 'An added file remained after rollback'); unlink($root.'/config/new.cfg');
    file_put_contents($root.'/run.sh', 'wrong launcher');
    rejects(fn () => $verifier->verify($root, $receipt), 'Changed launcher accepted'); file_put_contents($root.'/run.sh', 'original launcher');
    file_put_contents($root.'/config/managed.cfg', 'local drift');
    rejects(fn () => $verifier->capture($root, $changes, ['run.sh' => $launcher]), 'Pre-backup local drift accepted'); file_put_contents($root.'/config/managed.cfg', 'original');
    foreach (['private-world/playerdata/large.dat', 'world/level.dat', 'dimensions/region/data.mca', 'data/runtime.dat', 'mods/../world/level.dat'] as $path) {
        rejects(fn () => $verifier->capture($root, [['path' => $path, 'action' => 'remove', 'local_sha256' => null]], ['run.sh' => $launcher]), 'Unsafe path entered recovery receipt: '.$path);
    }
    if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
        mkdir($root.'/config/blocked', 0700); file_put_contents($root.'/config/blocked/new.cfg', 'leftover');
        $blocked = $receipt; $blocked['hashes']['config/blocked/new.cfg'] = null; chmod($root.'/config/blocked', 0000);
        try { rejects(fn () => $verifier->verify($root, $blocked), 'Inaccessible managed destination was mistaken for confirmed absence'); }
        finally { chmod($root.'/config/blocked', 0700); unlink($root.'/config/blocked/new.cfg'); rmdir($root.'/config/blocked'); }
    }
    rejects(fn () => $verifier->verify($root, ['sha256' => $hash, 'entries' => 100]), 'Obsolete full-volume receipt accepted');
} finally {
    chmod($root.'/private-world', 0700); chmod($root.'/private-world/playerdata/large.dat', 0600);
    unlink($root.'/private-world/playerdata/large.dat'); rmdir($root.'/private-world/playerdata'); rmdir($root.'/private-world');
    foreach (['config/managed.cfg', 'run.sh', 'server.properties'] as $path) { unlink($root.'/'.$path); }
    rmdir($root.'/config'); rmdir($root);
}
echo "Scoped recovery receipts: unreadable 8 GiB world excluded, conditional hashes, absence, launcher and unsafe scope checks passed\n";
