<?php

declare(strict_types=1);
namespace Arcturus\ModpackManager\Native;

use Illuminate\Cache\{FileLock, FileStore};
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/** Explicit repair of ONE operator-proven orphan; never an automatic stale-lock heuristic. */
final class FileLeaseRepair
{
    private function path(int $server): string
    {
        $lock = Cache::lock('arcturus-modpack-manager-deploy-'.$server, 14400);
        if (! $lock instanceof FileLock) { throw new RuntimeException('Orphan repair supports only the inspected Laravel FileStore contract.'); }
        $store = (new \ReflectionProperty($lock, 'store'))->getValue($lock);
        if (! $store instanceof FileStore) { throw new RuntimeException('Unexpected FileLock store.'); }
        return $store->path((new \ReflectionProperty($lock, 'name'))->getValue($lock));
    }

    private function record($handle, string $path): array
    {
        clearstatcache(true, $path); $stat = lstat($path); $opened = fstat($handle);
        if (! $stat || ($stat['mode'] & 0170000) !== 0100000 || $stat['ino'] !== $opened['ino'] || $stat['dev'] !== $opened['dev']) { throw new RuntimeException('Lease path/inode changed or is not a regular file.'); }
        rewind($handle); $bytes = stream_get_contents($handle, 4097);
        if (strlen($bytes) > 4096 || ! preg_match('/^[0-9]{10}/D', $bytes) || ! is_string(@unserialize(substr($bytes, 10), ['allowed_classes' => false]))) { throw new RuntimeException('Unknown FileStore lock encoding. No repair attempted.'); }
        return ['bytes' => $bytes, 'inode' => $stat['ino'], 'device' => $stat['dev'], 'expires' => (int) substr($bytes, 0, 10), 'mtime' => $stat['mtime']];
    }

    private function proof(array $record, string $identity): string
    { return hash_hmac('sha256', serialize([$record, $identity]), (string) config('app.key')); }

    public function inspect(int $server, string $identity): array
    {
        $path = $this->path($server);
        $handle = @fopen($path, 'rb');
        if (! $handle) { throw new RuntimeException('No readable FileStore lease exists. Retry handoff; do not create or delete a lock manually.'); }
        try {
            if (! flock($handle, LOCK_SH | LOCK_NB)) { throw new RuntimeException('FileStore is changing this lock now. Retry inspection later.'); }
            $record = $this->record($handle, $path);
            return ['proof' => $this->proof($record, $identity), 'path' => $path, 'expires_at_utc' => gmdate('c', $record['expires']),
                'modified_at_utc' => gmdate('c', $record['mtime']), 'expired' => $record['expires'] <= \Illuminate\Support\Carbon::now()->timestamp,
                'message' => 'This record does NOT prove the writer is dead. Independently quiesce Panel/queue/Artisan writers and verify Wings work is finished before explicit orphan repair. Owner tokens are never printed.'];
        } finally { fclose($handle); }
    }

    public function expire(int $server, string $identity, string $proof, \Closure $revalidate): void
    {
        $path = $this->path($server); $handle = @fopen($path, 'r+b');
        if (! $handle) { throw new RuntimeException('Inspected lease disappeared. Inspect again.'); }
        try {
            if (! flock($handle, LOCK_EX | LOCK_NB)) { throw new RuntimeException('FileStore is changing the lease. Nothing released.'); }
            $record = $this->record($handle, $path);
            if (! hash_equals($this->proof($record, $identity), $proof)) { throw new RuntimeException('Lease owner, expiry, inode or checkpoint changed. Nothing released. Inspect again.'); }
            $revalidate();
            if ($this->record($handle, $path) !== $record) { throw new RuntimeException('Lease changed during orphan verification. Nothing released.'); }
            // Keep the inode: FileStore::add serializes on this very flock. Unlink would let a
            // waiter acquire the old inode while a new owner acquires a newly created file.
            rewind($handle); $expired = '0000000001'.substr($record['bytes'], 10);
            if (fwrite($handle, $expired) !== strlen($expired) || ! ftruncate($handle, strlen($expired)) || ! fflush($handle)) { throw new RuntimeException('Could not expire the exact orphan lease. Inspect before any retry.'); }
        } finally { fclose($handle); }
    }
}
