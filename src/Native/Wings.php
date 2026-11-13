<?php

declare(strict_types=1);
namespace Arcturus\ModpackManager\Native;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use Arcturus\ModpackManager\Services\UpdatePlanner;
use RuntimeException;

/** Pelican rc.2 authenticated binary streams; no client token and no host-volume access. */
final class Wings implements Files
{
    public const MAX_FILE_BYTES = 2147483648;

    public function __construct(private readonly Server $server, private readonly DaemonFileRepository $daemon,
        private readonly ?\Closure $heartbeat = null) {}

    // Share only the transport pool within this server operation. Every request still
    // gets fresh Pelican authentication, node identity checks and Laravel middleware.
    private mixed $transport = null;

    private function client(): \Illuminate\Http\Client\PendingRequest
    {
        $this->transport ??= \GuzzleHttp\Utils::chooseHandler();
        return $this->repo()->getHttpClient()->setHandler($this->transport);
    }

    private function repo(): DaemonFileRepository { return $this->daemon->setServer($this->server); }

    /** Read Docker stdout/stderr via Wings, including for a stopped container. */
    public function consoleTail(): array
    {
        $deadline = hrtime(true) + 3_000_000_000;
        $response = $this->client()->connectTimeout(1)->timeout(3)->withOptions([
            'stream' => true, 'read_timeout' => 1, 'allow_redirects' => false,
        ])->get('/api/servers/'.$this->server->uuid.'/logs', ['size' => 60]);
        $stream = $response->toPsrResponse()->getBody(); $data = ''; $limit = 524288;
        try {
            if ($response->status() !== 200) { throw new RuntimeException('Wings console is unavailable.'); }
            while (! $stream->eof()) {
                if (hrtime(true) >= $deadline) { throw new RuntimeException('Wings console read timed out.'); }
                $chunk = $stream->read(min(16384, $limit + 1 - strlen($data)));
                if ($chunk === '' && ! $stream->eof()) { throw new RuntimeException('Wings console stream stalled.'); }
                $data .= $chunk;
                if (strlen($data) > $limit) { throw new RuntimeException('Wings console response exceeds the diagnostic limit.'); }
            }
        } finally { $stream->close(); }
        $decoded = json_decode($data, true, 8, JSON_THROW_ON_ERROR);
        if (! is_array($decoded) || ! array_key_exists('data', $decoded)) { throw new RuntimeException('Invalid Wings console response.'); }
        $lines = $decoded['data'] ?? []; // Wings encodes an empty Go slice as null.
        if (! is_array($lines) || ! array_is_list($lines) || count($lines) > 60) { throw new RuntimeException('Invalid Wings console lines.'); }
        foreach ($lines as $line) {
            if (! is_string($line)) { throw new RuntimeException('Invalid Wings console line.'); }
        }
        return $lines;
    }

    public function listing(string $directory = ''): array
    {
        if ($directory !== '') { UpdatePlanner::assertPath($directory); }
        // Directory reads are idempotent. Retry only transport connection failures;
        // never replay mutations or bypass node authentication failures.
        for ($attempt = 0; ; $attempt++) {
            try {
                $rows = $this->client()->get('/api/servers/'.$this->server->uuid.'/files/list-directory',
                    ['directory' => $directory === '' ? '/' : '/'.$directory])->json();
                break;
            } catch (\Illuminate\Http\Client\ConnectionException $e) {
                if (! $e->getPrevious() instanceof \GuzzleHttp\Exception\ConnectException) { throw $e; }
                if ($attempt >= 2) { throw new WingsConnectionException($e->getMessage(), 0, $e); }
                $this->heartbeat?->__invoke();
                usleep(250000 * ($attempt + 1));
            }
        }
        if (! array_is_list($rows) || count($rows) > 100000) { throw new RuntimeException('Wings returned an invalid or excessive directory listing.'); }
        $seen = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! is_string($row['name'] ?? null) || str_contains($row['name'], '/') ||
                isset($seen[$row['name']]) || ! is_bool($row['file'] ?? null) || ! is_bool($row['directory'] ?? null) || ! is_bool($row['symlink'] ?? null)) {
                throw new RuntimeException('Wings listing is incomplete or ambiguous; native mutation is blocked.');
            }
            UpdatePlanner::assertPath($row['name']);
            $seen[$row['name']] = true;
        }
        return $rows;
    }

    /** Inspect each ancestor using lstat metadata. Never follow volume symlinks. */
    public function stat(string $path): ?array
    {
        return $this->metadata($path, false);
    }

    private function metadata(string $path, bool $allowLeafSymlink): ?array
    {
        UpdatePlanner::assertPath($path);
        $parts = explode('/', $path); $parent = '';
        foreach ($parts as $index => $part) {
            $found = null;
            foreach ($this->listing($parent) as $row) { if ($row['name'] === $part) { $found = $row; break; } }
            if ($found === null) { return null; }
            if ($found['symlink'] && ! ($allowLeafSymlink && $index === count($parts) - 1)) { throw new SymlinkPath($parent.($parent === '' ? '' : '/').$part); }
            if ($index === count($parts) - 1) { return $found; }
            if (! $found['directory'] || $found['file']) { throw new RuntimeException('Native operation parent is not a regular directory.'); }
            $parent = $parent === '' ? $part : $parent.'/'.$part;
        }
        throw new RuntimeException('Invalid native file path.');
    }

    private function regular(string $path): ?array
    {
        $meta = $this->stat($path);
        if ($meta !== null && (! $meta['file'] || $meta['directory'] || ! is_int($meta['size'] ?? null) || $meta['size'] < 0 || $meta['size'] > self::MAX_FILE_BYTES)) {
            throw new RuntimeException('Native file is not regular or exceeds the 2 GiB per-file limit: '.$path);
        }
        return $meta;
    }

    /** Stream only a requested candidate. No recursive enumeration or world hashing. */
    public function hash(string $path): ?string
    {
        $before = $this->regular($path);
        if ($before === null) { return null; }
        $response = $this->client()->connectTimeout(5)->timeout(600)->withOptions(['stream' => true])
            ->get('/api/servers/'.$this->server->uuid.'/files/contents', ['file' => '/'.$path])->throw();
        $stream = $response->toPsrResponse()->getBody();
        $hash = hash_init('sha256'); $bytes = 0;
        try {
            while (! $stream->eof()) {
                $chunk = $stream->read(1048576);
                if ($chunk === '' && ! $stream->eof()) { throw new RuntimeException('Wings file stream stalled.'); }
                $bytes += strlen($chunk);
                if ($bytes > $before['size']) { throw new RuntimeException('Wings file grew while being verified.'); }
                hash_update($hash, $chunk); $this->heartbeat?->__invoke();
            }
        } finally { $stream->close(); }
        $after = $this->regular($path);
        if ($bytes !== $before['size'] || $after !== $before) { throw new RuntimeException('Wings file changed or transfer was truncated during verification.'); }
        return hash_final($hash);
    }

    public function read(string $path, int $limit = 1048576): string
    {
        if ($limit < 1 || $limit > 4194304) { throw new RuntimeException('Native text read limit exceeded.'); }
        $before = $this->regular($path);
        if ($before === null || $before['size'] > $limit) { throw new RuntimeException('Required native text file is missing or too large: '.$path); }
        $response = $this->client()->connectTimeout(5)->timeout(30)->withOptions(['stream' => true])
            ->get('/api/servers/'.$this->server->uuid.'/files/contents', ['file' => '/'.$path])->throw();
        $stream = $response->toPsrResponse()->getBody(); $data = '';
        try {
            while (! $stream->eof()) {
                $chunk = $stream->read(min(65536, $limit + 1 - strlen($data)));
                if ($chunk === '' && ! $stream->eof()) { throw new RuntimeException('Wings text stream stalled.'); }
                $data .= $chunk;
                if (strlen($data) > $limit) { throw new RuntimeException('Wings text file grew beyond its limit.'); }
            }
        } finally { $stream->close(); }
        if ($this->regular($path) !== $before) { throw new TextChangedDuringInspection('Native text changed during inspection: '.$path); }
        if (strlen($data) !== $before['size']) { throw new RuntimeException('Wings text transfer length did not match file metadata: '.$path); }
        return $data;
    }

    public function upload(string $localFile, string $path, string $sha256): void
    {
        UpdatePlanner::assertPath($path);
        if (! preg_match('/^[a-f0-9]{64}$/D', $sha256) || is_link($localFile) || ! is_file($localFile) || filesize($localFile) > self::MAX_FILE_BYTES ||
            ! hash_equals($sha256, hash_file('sha256', $localFile))) { throw new RuntimeException('Prepared artifact does not match its exact manifest.'); }
        if ($this->stat($path) !== null) { throw new RuntimeException('Native upload destination already exists.'); }
        $stream = fopen($localFile, 'rb');
        if ($stream === false) { throw new RuntimeException('Cannot open private prepared artifact.'); }
        try {
            // Keep a slow transfer live in the durable journal. A fenced operation
            // aborts the transfer through Guzzle instead of continuing an orphaned write.
            $lastPulse = 0.0;
            $progress = function () use (&$lastPulse): void {
                if (microtime(true) - $lastPulse >= 10) { $this->heartbeat?->__invoke(); $lastPulse = microtime(true); }
            };
            // Wings requires Content-Length and streams the raw body into this exact file.
            $this->client()->connectTimeout(5)->timeout(600)->withOptions(['progress' => $progress])->withQueryParameters(['file' => '/'.$path])
                ->withHeaders(['Content-Length' => (string) filesize($localFile)])
                ->withBody($stream, 'application/octet-stream')->post('/api/servers/'.$this->server->uuid.'/files/write')->throw();
        } finally { if (is_resource($stream)) { fclose($stream); } }
        if ($this->hash($path) !== $sha256) { throw new RuntimeException('Wings upload verification failed.'); }
    }

    public function permissions(string $path, ?int $mode = null): ?int
    {
        $meta = $this->regular($path);
        if ($meta === null) { return null; }
        if (! is_string($meta['mode_bits'] ?? null) || ! preg_match('/^[0-7]{1,3}$/D', $meta['mode_bits'])) { throw new RuntimeException('Wings did not provide valid file permission metadata.'); }
        if ($mode === null) { return octdec($meta['mode_bits']); }
        if ($mode < 0 || $mode > 0777) { throw new RuntimeException('Only ordinary file permission bits can be retained.'); }
        $this->repo()->chmodFiles('/', [['file' => $path, 'mode' => decoct($mode)]])->throw();
        if ($this->permissions($path) !== $mode) { throw new RuntimeException('Wings did not retain the existing file permissions.'); }
        return $mode;
    }

    public function mkdir(string $path): void
    {
        UpdatePlanner::assertPath($path);
        $parent = ''; foreach (explode('/', $path) as $part) {
            $next = $parent === '' ? $part : $parent.'/'.$part;
            $meta = $this->stat($next);
            if ($meta === null) {
                $this->repo()->createDirectory($part, $parent === '' ? '/' : '/'.$parent)->throw();
                $meta = $this->stat($next);
            }
            if (! $meta || ! $meta['directory'] || $meta['file'] || $meta['symlink']) { throw new RuntimeException('Wings did not create a safe directory.'); }
            $parent = $next;
        }
    }

    public function rename(string $from, string $to): void
    {
        UpdatePlanner::assertPath($from); UpdatePlanner::assertPath($to);
        if ($this->stat($from) === null || $this->stat($to) !== null) { throw new RuntimeException('Wings rename source/destination changed.'); }
        $this->repo()->renameFiles('/', [['from' => $from, 'to' => $to]])->throw();
        if ($this->stat($from) !== null || $this->stat($to) === null) { throw new RuntimeException('Wings acknowledged a rename without completing it.'); }
    }

    public function linkMetadata(string $path): ?array { return $this->metadata($path, true); }

    public static function sameLink(array $expected, ?array $actual): bool
    {
        if ($actual === null || ! ($expected['symlink'] ?? false) || ! ($actual['symlink'] ?? false)) { return false; }
        foreach (['size', 'modified'] as $key) { if (! array_key_exists($key, $expected) || ($actual[$key] ?? null) !== $expected[$key]) { return false; } }
        return true;
    }

    /** Rename a leaf symlink itself, with no dereference, into/out of a retained recovery directory. */
    public function moveLink(string $from, string $to): void
    {
        UpdatePlanner::assertPath($from); UpdatePlanner::assertPath($to);
        $before = $this->metadata($from, true);
        if (! ($before['symlink'] ?? false) || $this->metadata($to, true) !== null) { throw new RuntimeException('Native launcher link move source/destination changed.'); }
        $this->repo()->renameFiles('/', [['from' => $from, 'to' => $to]])->throw();
        if ($this->metadata($from, true) !== null || ! self::sameLink($before, $this->metadata($to, true))) { throw new RuntimeException('Wings did not preserve the launcher link during rename.'); }
    }

    public function delete(string $path): void
    {
        UpdatePlanner::assertPath($path);
        $this->metadata($path, true); // Wings uses lstat: delete a leaf link, never follow an ancestor.
        $this->repo()->deleteFiles('/', [$path])->throw();
        if ($this->metadata($path, true) !== null) { throw new RuntimeException('Wings could not remove the exact entry; check its filesystem denylist.'); }
    }
}
