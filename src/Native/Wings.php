<?php

declare(strict_types=1);
namespace Arcturus\ModpackManager\Native;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use Arcturus\ModpackManager\Services\UpdatePlanner;
use RuntimeException;

/** Pelican rc.2 authenticated binary streams; no client token and no host-volume access. */
final class Wings implements BulkStaging
{
    public const MAX_FILE_BYTES = 2147483648;

    public function __construct(private readonly Server $server, private readonly DaemonFileRepository $daemon,
        private readonly ?\Closure $heartbeat = null, private readonly ?DeploymentMetrics $metrics = null) {}

    private ?ApplyBudget $budget = null;
    public function budget(?ApplyBudget $budget): void { $this->budget = $budget; }

    private function measured(string $category, \Closure $work): mixed
    { return $this->metrics !== null ? $this->metrics->measure($category, $work) : $work(); }

    private function boundedClient(int $maximum): \Illuminate\Http\Client\PendingRequest
    { return $this->client()->connectTimeout(5)->timeout($this->budget?->timeout($maximum) ?? $maximum)->withOptions(['allow_redirects' => false]); }

    private function mutation(string $endpoint, array $payload, string $method = 'post'): void
    {
        $this->heartbeat?->__invoke(true);
        $this->measured($endpoint, function () use ($endpoint, $payload, $method): void {
            $response = $this->boundedClient(60)->{$method}('/api/servers/'.$this->server->uuid.'/files/'.$endpoint, $payload)->throw();
            if ($response->status() !== 204) { throw new RuntimeException('Wings mutation did not return its direct completed response; never replay it.'); }
        });
    }

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
    public function consoleTail(int $linesLimit = 60): array
    {
        if ($linesLimit < 1 || $linesLimit > 100) { throw new RuntimeException('Invalid bounded Wings console line limit.'); }
        $deadline = hrtime(true) + 3_000_000_000;
        $response = $this->client()->connectTimeout(1)->timeout(3)->withOptions([
            'stream' => true, 'read_timeout' => 1, 'allow_redirects' => false,
        ])->get('/api/servers/'.$this->server->uuid.'/logs', ['size' => $linesLimit]);
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
        if (! is_array($lines) || ! array_is_list($lines) || count($lines) > $linesLimit) { throw new RuntimeException('Invalid Wings console lines.'); }
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
                $response = $this->measured('list-directory', fn () => $this->boundedClient(30)->get('/api/servers/'.$this->server->uuid.'/files/list-directory',
                    ['directory' => $directory === '' ? '/' : '/'.$directory])->throw());
                if ($response->status() !== 200) { throw new RuntimeException('Wings directory listing requires a direct complete response.'); }
                $this->metrics?->bytes('list-directory', $response->toPsrResponse()->getBody()->getSize() ?? 0);
                $rows = $response->json();
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
        return $this->streamHash($path, self::MAX_FILE_BYTES, 600);
    }

    /** Copy one selected JAR into an already open, Panel-private temporary file. */
    public function copyModForInspection(string $path, $sink, int $limit): string
    {
        if (! preg_match('~^mods/[^/]+\.jar(?:\.disabled)?$~iD', $path) || ! is_resource($sink) || $limit < 1 || $limit > 67108864) {
            throw new RuntimeException('Invalid native mod inspection request.');
        }
        return $this->streamHash($path, $limit, 60, $sink) ?? throw new RuntimeException('Native mod inspection candidate disappeared: '.$path);
    }

    public function copyCrucibleForInspection(string $path, $sink): string
    {
        if (! preg_match('~^Crucible(?:-[A-Za-z0-9_.+-]+)?\.jar$~iD', $path) || ! is_resource($sink)) {
            throw new RuntimeException('Invalid Crucible launcher inspection request.');
        }

        return $this->streamHash($path, 67108864, 60, $sink) ?? throw new RuntimeException('The active Crucible JAR is missing: '.$path);
    }

    private function streamHash(string $path, int $limit, int $timeout, $sink = null, ?array $verifiedMetadata = null, ?\Closure $capture = null): ?string
    {
        $before = $verifiedMetadata ?? $this->regular($path);
        if ($before === null) { return null; }
        if ($before['size'] > $limit) { throw new RuntimeException('Native file exceeds the inspection byte limit: '.$path); }
        return $this->measured('hash-read', function () use ($path, $limit, $timeout, $sink, $verifiedMetadata, $capture, $before): string {
            $options = ['stream' => true, 'read_timeout' => 5, 'allow_redirects' => false];
            $response = $this->boundedClient($timeout)->withOptions($options)
                ->get('/api/servers/'.$this->server->uuid.'/files/contents', ['file' => '/'.$path])->throw();
            $stream = $response->toPsrResponse()->getBody();
            $hash = hash_init('sha256'); $bytes = 0;
            $deadline = microtime(true) + ($this->budget?->timeout($timeout) ?? $timeout);
            try {
                if ($response->status() !== 200) { throw new RuntimeException('Native mod inspection requires a direct Wings file response.'); }
                while (! $stream->eof()) {
                    if (microtime(true) >= $deadline || ($this->budget !== null && $this->budget->timeout($timeout) < 5)) { throw new RuntimeException('Native mod inspection timed out before the next bounded read.'); }
                    $chunk = $stream->read(1048576);
                    if ($chunk === '' && ! $stream->eof()) { throw new RuntimeException('Wings file stream stalled.'); }
                    $bytes += strlen($chunk); $this->metrics?->bytes('hash-read', strlen($chunk));
                    $this->budget?->timeout($timeout);
                    if ($bytes > $before['size']) {
                        if ($capture !== null) { throw new TextChangedDuringInspection('Startup log grew during inspection.'); }
                        throw new RuntimeException('Wings file grew while being verified.');
                    }
                    if ($sink !== null && fwrite($sink, $chunk) !== strlen($chunk)) { throw new RuntimeException('Panel cannot save private mod inspection bytes.'); }
                    $capture?->__invoke($chunk);
                    hash_update($hash, $chunk); $this->heartbeat?->__invoke();
                }
            } finally { $stream->close(); }
            // A batch validates fresh ancestor and leaf metadata together after all bounded reads.
            $after = $verifiedMetadata ?? $this->regular($path);
            if ($capture !== null && $after !== $before) { throw new TextChangedDuringInspection('Startup log changed during inspection.'); }
            if ($bytes !== $before['size'] || $after !== $before) { throw new RuntimeException('Wings file changed or transfer was truncated during verification.'); }
            return hash_final($hash);
        });
    }

    /** Fresh snapshots are local to ONE read-only batch. Never reused by writes or another phase. */
    public function hashBatch(array $paths): array
    {
        if (count($paths) > 32 || ! array_is_list($paths)) { throw new RuntimeException('Native hash batch must contain at most 32 explicit paths.'); }
        $before = $this->batchMetadata($paths);
        $hashes = $this->parallelHashes($paths, $before[0]);
        if ($this->batchMetadata($paths) !== $before) { throw new RuntimeException('Native batch file or ancestor changed during verification.'); }
        return $hashes;
    }

    /** Comparison protects linked leaves/ancestors individually, while failing the whole batch on read errors. */
    public function inventoryBatch(array $paths): array
    {
        if (count($paths) > 32 || ! array_is_list($paths)) { throw new RuntimeException('Invalid native inventory batch.'); }
        $before = $this->batchMetadata($paths, true);
        $hashes = $this->parallelHashes(array_values(array_diff($paths, array_keys($before[2]))), $before[0]);
        if ($this->batchMetadata($paths, true) !== $before) { throw new RuntimeException('Inventory file, missing path, symlink or ancestor changed during inspection.'); }
        return ['hashes' => $hashes, 'links' => $before[2]];
    }

    /** Async curl transfers use disk sinks: stream=true would select Guzzle's synchronous handler. */
    private function parallelHashes(array $paths, array $metadata): array
    {
        $hashes = [];
        $batches = []; $batch = []; $bytes = 0;
        foreach ($paths as $path) {
            $size = $metadata[$path]['size'] ?? 0;
            if ($batch !== [] && (count($batch) >= 8 || $bytes + $size > 67108864)) { $batches[] = $batch; $batch = []; $bytes = 0; }
            $batch[] = $path; $bytes += $size;
        }
        if ($batch !== []) { $batches[] = $batch; }
        foreach ($batches as $batch) {
            $free = disk_free_space(sys_get_temp_dir());
            $needed = array_sum(array_map(fn ($path) => $metadata[$path]['size'] ?? 0, $batch));
            if ($free === false || $free < $needed + 1048576) { throw new RuntimeException('Insufficient private disk for bounded Wings verification sinks.'); }
            $sinks = []; $promises = [];
            try {
                foreach ($batch as $path) {
                    if ($metadata[$path] === null) { $hashes[$path] = null; continue; }
                    $sink = tmpfile();
                    if ($sink === false) { throw new RuntimeException('Cannot allocate private verification disk sink.'); }
                    $sinks[$path] = $sink; $limit = $metadata[$path]['size'];
                    $this->heartbeat?->__invoke();
                    $promises[$path] = $this->boundedClient(600)->withOptions([
                        'sink' => $sink,
                        'on_stats' => function (\GuzzleHttp\TransferStats $stats): void {
                            $this->metrics?->record('contents-request', (float) $stats->getTransferTime(), (int) ($stats->getHandlerStats()['size_download'] ?? 0), ! $stats->hasResponse() || $stats->getResponse()->getStatusCode() !== 200);
                        },
                        'progress' => function ($total, $received) use ($limit): void {
                            if ($received > $limit) { throw new RuntimeException('Wings verification exceeds approved metadata size.'); }
                            $this->budget?->timeout(600); $this->heartbeat?->__invoke();
                        },
                    ])->async()->get('/api/servers/'.$this->server->uuid.'/files/contents', ['file' => '/'.$path]);
                    // RC2 Laravel returns lazy promises: build ALL before waiting on any.
                    if (method_exists($promises[$path], 'buildPromise')) { $promises[$path] = $promises[$path]->buildPromise(); }
                }
                // Settle every launched read before releasing resources, even if another read failed.
                $results = \GuzzleHttp\Promise\Utils::settle($promises)->wait();
                foreach ($results as $path => $result) {
                    $this->measured('hash-read', function () use ($result, $path, $metadata, &$hashes): void {
                        if ($result['state'] !== 'fulfilled') { throw $result['reason']; }
                        $response = $result['value'];
                        if ($response instanceof \Throwable) { throw $response; }
                        if ($response->status() !== 200) { throw new RuntimeException('Wings parallel read requires a direct complete response.'); }
                        $stream = $response->toPsrResponse()->getBody(); $hash = hash_init('sha256'); $bytes = 0;
                        try {
                            if ($stream->isSeekable()) { $stream->rewind(); }
                            while (! $stream->eof()) {
                                $chunk = $stream->read(1048576); $bytes += strlen($chunk);
                                $this->metrics?->bytes('hash-read', strlen($chunk));
                                if ($bytes > $metadata[$path]['size'] || ($chunk === '' && ! $stream->eof())) { throw new RuntimeException('Invalid Wings verification stream.'); }
                                hash_update($hash, $chunk); $this->heartbeat?->__invoke(); $this->budget?->timeout(600);
                            }
                        } finally { $stream->close(); }
                        if ($bytes !== $metadata[$path]['size']) { throw new RuntimeException('Truncated parallel Wings read.'); }
                        $hashes[$path] = hash_final($hash);
                    });
                }
            } finally {
                // Requests may have been scheduled before a local allocation/ownership failure.
                if ($promises !== []) { \GuzzleHttp\Promise\Utils::settle($promises)->wait(); }
                foreach ($sinks as $sink) { if (is_resource($sink)) { fclose($sink); } }
            }
        }
        return array_replace(array_fill_keys($paths, null), $hashes);
    }

    private function batchMetadata(array $paths, bool $protectLinks = false): array
    {
        $directories = []; $metadata = []; $ancestors = []; $links = [];
        foreach ($paths as $path) {
            UpdatePlanner::assertPath($path);
            $parent = ''; $parts = explode('/', $path);
            foreach ($parts as $index => $part) {
                if (! array_key_exists($parent, $directories)) {
                    $directories[$parent] = array_column($this->listing($parent), null, 'name');
                }
                $row = $directories[$parent][$part] ?? null;
                $next = $parent === '' ? $part : $parent.'/'.$part;
                if ($row === null) { $metadata[$path] = null; break; }
                if ($row['symlink']) {
                    if (! $protectLinks) { throw new SymlinkPath($next); }
                    $metadata[$path] = null; $links[$path] = $next; $ancestors[$next] = $row; break;
                }
                if (! is_string($row['modified'] ?? null) || $row['modified'] === '') { throw new RuntimeException('Wings batch metadata lacks a modification timestamp.'); }
                if ($index === count($parts) - 1) {
                    if (! $row['file'] || $row['directory'] || ! is_int($row['size'] ?? null) || $row['size'] < 0 || $row['size'] > self::MAX_FILE_BYTES) {
                        throw new RuntimeException('Native batch candidate is not a regular bounded file.');
                    }
                    $metadata[$path] = $row;
                } else {
                    if (! $row['directory'] || $row['file']) { throw new RuntimeException('Native batch ancestor is not a regular directory.'); }
                    $ancestors[$next] = $row;
                    $parent = $next;
                }
            }
            $this->heartbeat?->__invoke();
        }
        return [$metadata, $ancestors, $links];
    }

    /** Bounded in-memory head/tail, streamed full hash. A busy or replaced log is never accepted. */
    public function bootLogEvidence(): array
    {
        $head = ''; $tail = ''; $bytes = 0; $done = false; $overlap = '';
        $digest = $this->streamHash('logs/latest.log', self::MAX_FILE_BYTES, 600, null, null,
            function (string $chunk) use (&$head, &$tail, &$bytes, &$done, &$overlap): void {
                $bytes += strlen($chunk);
                $scan = $overlap.$chunk;
                $done = $done || preg_match('/\bDone \([\d.]+s\)!/i', $scan) === 1;
                $overlap = substr($scan, -128);
                if (strlen($head) < 1048576) { $head .= substr($chunk, 0, 1048576 - strlen($head)); }
                $tail = substr($tail.$chunk, -3145728);
            });
        if ($digest === null) { throw new RuntimeException('Current startup log is missing.'); }
        $text = $bytes <= 3145728 ? $tail : ($bytes <= 4194304 ? substr($head, 0, $bytes - 3145728).$tail : $head."\n".$tail);
        return ['text' => $text, 'sha256' => $digest, 'done' => $done];
    }

    public function read(string $path, int $limit = 1048576): string
    { return $this->measured('text-read', fn () => $this->readText($path, $limit)); }

    private function readText(string $path, int $limit): string
    {
        if ($limit < 1 || $limit > 4194304) { throw new RuntimeException('Native text read limit exceeded.'); }
        $before = $this->regular($path);
        if ($before === null || $before['size'] > $limit) { throw new RuntimeException('Required native text file is missing or too large: '.$path); }
        $response = $this->boundedClient(30)->withOptions(['stream' => true, 'read_timeout' => 5])
            ->get('/api/servers/'.$this->server->uuid.'/files/contents', ['file' => '/'.$path])->throw();
        $stream = $response->toPsrResponse()->getBody(); $data = '';
        try {
            while (! $stream->eof()) {
                if ($this->budget !== null && $this->budget->timeout(30) < 5) { throw new RuntimeException('Applying budget cannot fit the next bounded text read.'); }
                $chunk = $stream->read(min(65536, $limit + 1 - strlen($data)));
                if ($chunk === '' && ! $stream->eof()) { throw new RuntimeException('Wings text stream stalled.'); }
                $data .= $chunk; $this->metrics?->bytes('text-read', strlen($chunk));
                $this->budget?->timeout(30);
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
        $this->writeUpload($localFile, $path);
        if ($this->hash($path) !== $sha256) { throw new RuntimeException('Wings upload verification failed.'); }
    }

    private function writeUpload(string $localFile, string $path): void
    {
        $this->heartbeat?->__invoke(true);
        $this->measured('upload', function () use ($localFile, $path): void {
            $stream = fopen($localFile, 'rb');
            if ($stream === false) { throw new RuntimeException('Cannot open private prepared artifact.'); }
            $lastPulse = 0.0; $sent = 0;
            $progress = function ($downloadTotal, $downloaded, $uploadTotal, $uploaded) use (&$lastPulse, &$sent): void {
                $sent = max($sent, (int) $uploaded);
                $this->budget?->timeout(600);
                if (microtime(true) - $lastPulse >= 10) { $this->heartbeat?->__invoke(); $lastPulse = microtime(true); }
            };
            try {
                $response = $this->boundedClient(600)->withOptions(['progress' => $progress])->withQueryParameters(['file' => '/'.$path])
                    ->withHeaders(['Content-Length' => (string) filesize($localFile)])
                    ->withBody($stream, 'application/octet-stream')->post('/api/servers/'.$this->server->uuid.'/files/write')->throw();
                if ($response->status() !== 204) { throw new RuntimeException('Wings upload outcome is not confirmed; never replay it.'); }
                $sent = max($sent, filesize($localFile));
            } finally {
                $this->metrics?->bytes('upload', $sent);
                if (is_resource($stream)) { fclose($stream); }
            }
        });
    }

    /** Fresh metadata lives only in this isolated staging batch, never across a live mutation. */
    public function uploadBatch(string $directory, array $uploads): void
    {
        if (! preg_match('/^\.arcturus-update-[a-f0-9-]{32,36}\/new$/D', $directory) || $uploads === [] || count($uploads) > 4) {
            throw new RuntimeException('Invalid isolated upload batch.');
        }
        foreach ($uploads as $path => $row) {
            if (! is_string($path) || ! preg_match('/^[0-9]+$/D', basename($path)) || dirname($path) !== $directory) { throw new RuntimeException('Upload batch escaped isolated numeric staging.'); }
            UpdatePlanner::assertPath($path);
            $local = $row['local']; $digest = $row['sha256'];
            if (! is_string($digest) || ! preg_match('/^[a-f0-9]{64}$/D', $digest) || is_link($local) || ! is_file($local) || filesize($local) > self::MAX_FILE_BYTES || ! hash_equals($digest, hash_file('sha256', $local))) {
                throw new RuntimeException('Prepared artifact does not match its exact manifest.');
            }
        }
        $paths = array_keys($uploads); $before = $this->batchMetadata($paths);
        foreach ($before[0] as $meta) { if ($meta !== null) { throw new RuntimeException('Upload batch destination already exists; do not replay.'); } }
        // Missing ancestors must not count as absent leaves. No mkdir is performed by uploadBatch.
        foreach ([dirname($directory), $directory] as $ancestor) {
            if (! isset($before[1][$ancestor])) { throw new RuntimeException('Private upload directory is missing.'); }
        }
        foreach ($uploads as $path => $row) { $this->writeUpload($row['local'], $path); }
        $written = $this->batchMetadata($paths);
        foreach ($uploads as $path => $row) {
            if ($written[0][$path] === null || $this->streamHash($path, self::MAX_FILE_BYTES, 600, null, $written[0][$path]) !== $row['sha256']) {
                throw new RuntimeException('Wings staging upload batch failed SHA-256 verification; do not replay.');
            }
        }
        if ($this->batchMetadata($paths) !== $written) { throw new RuntimeException('Staging batch changed during hash verification; do not replay.'); }
    }

    /** Called only after exact locked backup and maintenance, with a durable bundle intent. */
    public function uploadBundle(string $directory, array $bundle, string $verification): void
    {
        if (! preg_match('/^\.arcturus-update-[a-f0-9-]{32,36}\/new\/b[0-9]+$/D', $directory) ||
            ! in_array($verification, ['parallel', 'archive'], true) || count($bundle['members']) > PreparedStaging::FILES) {
            throw new RuntimeException('Invalid bulk staging request.');
        }
        $expected = [];
        foreach ($bundle['members'] as $name => $row) {
            if (! preg_match('/^(0|[1-9][0-9]*)$/D', (string) $name)) { throw new RuntimeException('Invalid numeric staging member.'); }
            $expected[$directory.'/'.$name] = $row['sha256'];
        }
        if ($expected === []) { throw new RuntimeException('Empty staging bundle.'); }
        $archive = $directory.'/bundle-'.array_key_first($bundle['members']).'.zip';
        $before = $this->batchMetadata([...array_keys($expected), $archive]);
        foreach ($before[0] as $meta) { if ($meta !== null) { throw new RuntimeException('Bulk staging destination exists; never replay.'); } }
        foreach ([dirname($directory), $directory] as $ancestor) {
            if (! isset($before[1][$ancestor])) { throw new RuntimeException('Bulk staging directory is missing.'); }
        }
        // Verify private bytes again before submitting the one non-retried write.
        if (hash_file('sha256', $bundle['local']) !== $bundle['sha256']) { throw new RuntimeException('Private bundle drift.'); }
        $this->writeUpload($bundle['local'], $archive);
        if ($this->hash($archive) !== $bundle['sha256']) { throw new RuntimeException('Remote staging archive SHA-256 differs; refuse extraction.'); }
        try {
            $this->mutation('decompress', ['root' => '/'.$directory, 'file' => basename($archive)]);
        } catch (\Throwable $e) {
            throw new ArchiveOutcomeUnknown('Wings decompression outcome unknown; retain maintenance and stage until daemon work is confirmed finished.', 0, $e);
        }
        $this->delete($archive);
        if ($verification === 'archive') { $this->verifyCompressed($directory, $bundle['members']); }
        else {
            foreach (array_chunk($expected, 32, true) as $batch) {
                if ($this->hashBatch(array_keys($batch)) !== $batch) { throw new RuntimeException('Extracted Wings member SHA-256 differs.'); }
            }
        }
        // Exact set across ALL completed bundles is checked by the transaction before live mutation.
    }

    private function verifyCompressed(string $directory, array $members): void
    {
        $name = 'readback-'.array_key_first($members);
        $this->heartbeat?->__invoke(true);
        try {
            $response = $this->measured('compress', fn () => $this->boundedClient(60)->post('/api/servers/'.$this->server->uuid.'/files/compress',
                ['root' => '/'.$directory, 'files' => array_map('strval', array_keys($members)), 'name' => $name, 'extension' => 'zip'])->throw());
        } catch (\Throwable $e) { throw new ArchiveOutcomeUnknown('Wings compression outcome unknown; retain stage and maintenance.', 0, $e); }
        $row = $response->json();
        if ($response->status() !== 200 || ($row['name'] ?? null) !== $name.'.zip') { throw new ArchiveOutcomeUnknown('Unexpected Wings archive identity; retain stage and maintenance.'); }
        $path = $directory.'/'.$row['name']; $sink = tmpfile();
        if ($sink === false) { throw new RuntimeException('Cannot allocate private readback disk.'); }
        try {
            $max = array_sum(array_column($members, 'size')) + count($members) * 4096 + 1048576;
            if ($this->streamHash($path, $max, 600, $sink) === null) { throw new RuntimeException('Wings readback is missing.'); }
            fflush($sink);
            PreparedStaging::verifyArchive(stream_get_meta_data($sink)['uri'], $members, $this->heartbeat);
        } finally { fclose($sink); }
        $this->delete($path);
    }

    public function permissions(string $path, ?int $mode = null): ?int
    {
        $meta = $this->regular($path);
        if ($meta === null) { return null; }
        if (! is_string($meta['mode_bits'] ?? null) || ! preg_match('/^[0-7]{1,3}$/D', $meta['mode_bits'])) { throw new RuntimeException('Wings did not provide valid file permission metadata.'); }
        if ($mode === null) { return octdec($meta['mode_bits']); }
        if ($mode < 0 || $mode > 0777) { throw new RuntimeException('Only ordinary file permission bits can be retained.'); }
        $this->mutation('chmod', ['root' => '/', 'files' => [['file' => $path, 'mode' => decoct($mode)]]]);
        if ($this->permissions($path) !== $mode) { throw new RuntimeException('Wings did not retain the existing file permissions.'); }
        return $mode;
    }

    public function permissionsBatch(array $paths): array
    {
        if (count($paths) > 64 || ! array_is_list($paths)) { throw new RuntimeException('Invalid permission batch.'); }
        $before = $this->batchMetadata($paths); $modes = [];
        foreach ($before[0] as $path => $meta) {
            if ($meta === null || ! is_string($meta['mode_bits'] ?? null) || ! preg_match('/^[0-7]{1,3}$/D', $meta['mode_bits'])) { throw new RuntimeException('Missing regular file permissions.'); }
            $modes[$path] = octdec($meta['mode_bits']);
        }
        if ($this->batchMetadata($paths) !== $before) { throw new RuntimeException('Permissions changed during batch inspection.'); }
        return $modes;
    }

    public function chmodBatch(array $modes): void
    {
        $this->permissionsBatch(array_keys($modes));
        $rows = [];
        foreach ($modes as $path => $mode) {
            if (! is_int($mode) || $mode < 0 || $mode > 0777) { throw new RuntimeException('Invalid ordinary permission bits.'); }
            $rows[] = ['file' => $path, 'mode' => decoct($mode)];
        }
        if ($rows === []) { return; }
        $this->mutation('chmod', ['root' => '/', 'files' => $rows]);
        if ($this->permissionsBatch(array_keys($modes)) !== $modes) { throw new RuntimeException('Wings chmod batch was incomplete; do not replay it.'); }
    }

    public function renameBatch(array $moves): void
    {
        if (count($moves) > 64) { throw new RuntimeException('Native rename batch exceeds 64 independent moves.'); }
        $paths = [...array_keys($moves), ...array_values($moves)];
        if (count(array_unique($paths)) !== count($paths)) { throw new RuntimeException('Dependent or overlapping rename batch.'); }
        foreach ($paths as $path) {
            foreach ($paths as $other) {
                if ($path !== $other && str_starts_with($path, $other.'/')) { throw new RuntimeException('Ancestor-dependent rename batch.'); }
            }
        }
        $before = $this->batchMetadata($paths); $rows = [];
        foreach ($moves as $from => $to) {
            if ($before[0][$from] === null || $before[0][$to] !== null) { throw new RuntimeException('Rename batch source/destination changed.'); }
            $rows[] = ['from' => $from, 'to' => $to];
        }
        if ($rows === []) { return; }
        // One request only. An error/timeout is ambiguous and must enter existing backup recovery.
        $this->mutation('rename', ['root' => '/', 'files' => $rows], 'put');
        $after = $this->batchMetadata($paths);
        foreach ($moves as $from => $to) {
            $old = $before[0][$from]; $new = $after[0][$to];
            if ($after[0][$from] !== null || $new === null) { throw new RuntimeException('Wings rename batch incomplete; do not replay it.'); }
            foreach (['size', 'modified', 'mode_bits'] as $key) {
                if (! array_key_exists($key, $old) || ($new[$key] ?? null) !== $old[$key]) { throw new RuntimeException('Renamed file metadata differs.'); }
            }
        }
    }

    public function mkdir(string $path): void
    {
        UpdatePlanner::assertPath($path);
        $parent = ''; foreach (explode('/', $path) as $part) {
            $next = $parent === '' ? $part : $parent.'/'.$part;
            $meta = $this->stat($next);
            if ($meta === null) {
                $this->mutation('create-directory', ['name' => $part, 'path' => $parent === '' ? '/' : '/'.$parent]);
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
        $this->mutation('rename', ['root' => '/', 'files' => [['from' => $from, 'to' => $to]]], 'put');
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
        $this->mutation('rename', ['root' => '/', 'files' => [['from' => $from, 'to' => $to]]], 'put');
        if ($this->metadata($from, true) !== null || ! self::sameLink($before, $this->metadata($to, true))) { throw new RuntimeException('Wings did not preserve the launcher link during rename.'); }
    }

    public function delete(string $path): void
    {
        UpdatePlanner::assertPath($path);
        $this->metadata($path, true); // Wings uses lstat: delete a leaf link, never follow an ancestor.
        $this->mutation('delete', ['root' => '/', 'files' => [$path]]);
        if ($this->metadata($path, true) !== null) { throw new RuntimeException('Wings could not remove the exact entry; check its filesystem denylist.'); }
    }
}
