<?php

declare(strict_types=1);
namespace Arcturus\ModpackManager\Native;

use RuntimeException;
use ZipArchive;

/** Panel-private disk archives. Only verified, explicitly approved files enter these ZIPs. */
final class PreparedStaging
{
    public const FILES = 256;
    public const BYTES = 67108864;
    public array $bundles = [];
    private string $directory;
    private array $manifest = [];

    public function __construct(string $stage, array $after, array $moves = [], ?\Closure $pulse = null)
    {
        $this->directory = sys_get_temp_dir().'/arcturus-bundles-'.bin2hex(random_bytes(16));
        if (! mkdir($this->directory, 0700)) { throw new RuntimeException('Cannot create private bundle directory.'); }
        $moved = [...array_keys($moves), ...array_values($moves)];
        $batch = []; $bytes = 0; $index = 0;
        try {
            foreach ($after as $path => $digest) {
                if ($digest === null || in_array($path, $moved, true)) { continue; }
                \Arcturus\ModpackManager\Services\UpdatePlanner::assertPath($path);
                // Reuse the symlink-safe local inventory, including every ancestor.
                $actual = (new \Arcturus\ModpackManager\Services\DirectoryInventory)->hashPaths($stage, [$path]);
                if (($actual[$path] ?? null) !== $digest) { throw new RuntimeException('Prepared bundle differs from approved SHA-256: '.$path); }
                $size = filesize($stage.'/'.$path);
                if ($size === false || $size > Wings::MAX_FILE_BYTES) { throw new RuntimeException('Invalid prepared bundle file size.'); }
                if ($batch !== [] && (count($batch) >= self::FILES || $bytes + $size > self::BYTES)) { $this->pack($batch); $batch = []; $bytes = 0; }
                $batch[(string) $index++] = ['path' => $path, 'local' => $stage.'/'.$path, 'size' => $size, 'sha256' => $digest];
                $this->manifest[$path] = $digest; $bytes += $size; $pulse?->__invoke();
            }
            if ($batch !== []) { $this->pack($batch); }
        } catch (\Throwable $e) { $this->close(); throw $e; }
    }

    private function pack(array $members): void
    {
        $free = disk_free_space($this->directory);
        if ($free === false || $free < array_sum(array_column($members, 'size')) + 1048576) { throw new RuntimeException('Insufficient Panel-private temporary disk for staging bundle.'); }
        $file = $this->directory.'/'.count($this->bundles).'.zip'; $zip = new ZipArchive;
        if ($zip->open($file, ZipArchive::CREATE | ZipArchive::EXCL) !== true) { throw new RuntimeException('Cannot create staging bundle.'); }
        try {
            foreach ($members as $name => $row) {
                if (! $zip->addFile($row['local'], (string) $name) || ! $zip->setCompressionName((string) $name, ZipArchive::CM_STORE) ||
                    ! $zip->setMtimeName((string) $name, 315532800) || ! $zip->setExternalAttributesName((string) $name, ZipArchive::OPSYS_UNIX, 0100644 << 16)) { throw new RuntimeException('Cannot populate staging bundle.'); }
            }
        } finally { if (! $zip->close()) { throw new RuntimeException('Cannot finish staging bundle (check private disk space).'); } }
        // addFile is lazy: validate the actual archived bytes after close, not just source files.
        if (filesize($file) > Wings::MAX_FILE_BYTES) { throw new RuntimeException('Prepared archive exceeds the Wings bounded file limit.'); }
        self::verifyArchive($file, $members);
        $this->bundles[] = ['local' => $file, 'size' => filesize($file), 'sha256' => hash_file('sha256', $file), 'members' => $members];
    }

    public function requirePlan(array $after, array $moves): void
    {
        $wanted = array_diff_key(array_filter($after, fn ($h) => $h !== null), array_flip([...array_keys($moves), ...array_values($moves)]));
        if ($wanted !== $this->manifest) { throw new RuntimeException('Prepared bundles do not match the exact transaction plan.'); }
        foreach ($this->bundles as $bundle) {
            if (is_link($bundle['local']) || ! is_file($bundle['local']) || filesize($bundle['local']) !== $bundle['size'] || hash_file('sha256', $bundle['local']) !== $bundle['sha256']) {
                throw new RuntimeException('Private staging bundle changed after preparation.');
            }
        }
    }

    /** Never extract a readback ZIP. Reject duplicates, extra/missing names, links and all wrong bytes. */
    public static function verifyArchive(string $file, array $members, ?\Closure $pulse = null): void
    {
        $zip = new ZipArchive;
        if ($zip->open($file, ZipArchive::RDONLY) !== true) { throw new RuntimeException('Invalid Wings verification archive.'); }
        try {
            if ($zip->numFiles !== count($members)) { throw new RuntimeException('Verification archive member set differs.'); }
            $seen = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i); $name = $stat['name'];
                if (! isset($members[$name]) || isset($seen[$name]) || $stat['size'] !== $members[$name]['size']) { throw new RuntimeException('Unexpected, duplicate or wrong-sized archive member.'); }
                $seen[$name] = true;
                if (! $zip->getExternalAttributesIndex($i, $os, $attrs) || ($os === ZipArchive::OPSYS_UNIX && (($attrs >> 16) & 0170000) !== 0100000)) { throw new RuntimeException('Nonregular archive member.'); }
                $stream = $zip->getStreamIndex($i); $hash = hash_init('sha256'); $bytes = 0;
                if ($stream === false) { throw new RuntimeException('Cannot read archive member.'); }
                try {
                    while (! feof($stream)) {
                        $chunk = fread($stream, 1048576);
                        if ($chunk === false || ($chunk === '' && ! feof($stream))) { throw new RuntimeException('Archive stream stalled.'); }
                        $bytes += strlen($chunk);
                        if ($bytes > $members[$name]['size']) { throw new RuntimeException('Archive member exceeds approved size.'); }
                        hash_update($hash, $chunk); $pulse?->__invoke();
                    }
                } finally { fclose($stream); }
                if ($bytes !== $members[$name]['size'] || hash_final($hash) !== $members[$name]['sha256']) { throw new RuntimeException('Archive member SHA-256 differs.'); }
            }
        } finally { $zip->close(); }
    }

    public function close(): void
    {
        if (! isset($this->directory) || ! is_dir($this->directory)) { return; }
        foreach (glob($this->directory.'/*') ?: [] as $file) { unlink($file); }
        rmdir($this->directory);
    }
    public function __destruct() { $this->close(); }
}
