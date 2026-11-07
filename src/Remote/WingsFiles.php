<?php

declare(strict_types=1);

namespace Arcturus\ModpackManager\Remote;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use Arcturus\ModpackManager\Contracts\ServerFiles;
use Arcturus\ModpackManager\Contracts\IntegrityWorker;
use Arcturus\ModpackManager\Services\UpdatePlanner;
use Illuminate\Http\Client\RequestException;
use RuntimeException;

/** Native Pelican file API with an explicitly selected integrity/staging backend. */
final class WingsFiles implements ServerFiles
{
    /** Diagnostic only: raw remote filenames are never included. */
    private array $listingWarnings = [];

    public function __construct(private readonly Server $server,
        private readonly DaemonFileRepository $daemon, private readonly IntegrityWorker $worker, private readonly ?string $operationId = null) {}

    /** @return list<string> */
    public function inspectionWarnings(): array
    {
        return array_values(array_unique($this->listingWarnings));
    }

    private function repo(): DaemonFileRepository
    {
        return $this->daemon->setServer($this->server);
    }

    private function path(string $path): string
    {
        UpdatePlanner::assertPath($path);

        return '/'.$path;
    }

    public function listing(string $directory = ''): array
    {
        try {
            $rows = $this->repo()->getDirectory($directory === '' ? '/' : $this->path($directory));
        } catch (RequestException $error) {
            // Some Wings builds accept "." as the root but return 400/404 for
            // "/". Only retry this read-only root request, never auth failures.
            if ($directory !== '' || ! in_array($error->response->status(), [400, 404], true)) {
                throw $error;
            }
            $rows = $this->repo()->getDirectory('.');
            $this->listingWarnings[] = 'Wings root required the alternate directory spelling';
        }
        if (! array_is_list($rows)) {
            throw new RuntimeException('Invalid Wings directory listing');
        }
        // Unsafe names can exist on a legitimate modded server (e.g. a colon in
        // a local-only filename). Ignore only these entries for inspection;
        // never let them block inspection of safe launcher/config files.
        $safe = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! is_string($row['name'] ?? null) ||
                str_contains($row['name'], '/') || ! is_bool($row['file'] ?? null) ||
                ! is_bool($row['directory'] ?? null) || ! is_bool($row['symlink'] ?? null)) {
                $this->listingWarnings[] = 'Wings listed invalid file metadata; ignored unsafe entries';
                continue;
            }
            try {
                UpdatePlanner::assertPath($row['name']);
            } catch (\InvalidArgumentException) {
                $this->listingWarnings[] = 'Wings listed a filename outside the safe inspection scope';
                continue;
            }
            $safe[] = $row;
        }

        return $safe;
    }

    public function stat(string $path): ?array
    {
        $this->path($path);
        $dir = dirname($path);
        foreach ($this->listing($dir === '.' ? '' : $dir) as $entry) {
            if ($entry['name'] === basename($path)) {
                return $entry;
            }
        }

        return null;
    }

    public function read(string $path, int $limit = 1048576): string
    {
        if ($limit < 1 || $limit > 2097152) {
            throw new RuntimeException('Configuration read limit exceeded');
        }
        $meta = $this->stat($path);
        if (! $meta || ! ($meta['file'] ?? false) || ($meta['symlink'] ?? true) ||
            ! is_numeric($meta['size'] ?? null) || $meta['size'] > $limit) {
            throw new RuntimeException('File is absent, unsafe, or exceeds the configuration read limit');
        }
        // getContent() buffers the entire body before checking Content-Length. Bound the stream instead.
        $response = $this->repo()->getHttpClient()->withOptions(['stream' => true])
            ->get('/api/servers/'.$this->server->uuid.'/files/contents', ['file' => $this->path($path)])->throw();
        $stream = $response->toPsrResponse()->getBody();
        try {
            $data = '';
            while (! $stream->eof() && strlen($data) <= $limit) {
                $data .= $stream->read(min(65536, $limit + 1 - strlen($data)));
            }
            if (strlen($data) > $limit) {
                throw new RuntimeException('Remote configuration grew beyond read limit');
            }

            return $data;
        } finally {
            $stream->close();
        }
    }

    public function write(string $path, string $content): void
    {
        if (strlen($content) > 1048576) {
            throw new RuntimeException('Configuration write exceeds 1 MiB');
        }
        $this->repo()->putContent($this->path($path), $content)->throw();
    }

    public function mkdir(string $path): void
    {
        $this->path($path);
        $parent = dirname($path);
        $this->repo()->createDirectory(basename($path), $parent === '.' ? '/' : $this->path($parent))->throw();
    }

    public function rename(string $from, string $to): void
    {
        $this->path($from);
        $this->path($to);
        $this->repo()->renameFiles('/', [['from' => $from, 'to' => $to]])->throw();
    }

    public function delete(string $path): void
    {
        $this->path($path);
        $this->repo()->deleteFiles('/', [$path])->throw();
    }

    public function copy(string $path): void
    {
        $this->repo()->copyFile($this->path($path))->throw();
    }

    public function capabilities(): array
    {
        return $this->worker->request('capabilities');
    }

    public function hashes(array $paths): array
    {
        foreach ($paths as $path) {
            UpdatePlanner::assertPath($path);
        }
        $data = $this->worker->request('hashes', ['paths' => array_values(array_unique($paths))]);
        $files = $data['files'] ?? null;
        if (! is_array($files) || ! isset($data['missing']) || ! is_array($data['missing'])) {
            throw new RuntimeException('Incomplete remote hash response');
        }
        $reported = array_merge(array_keys($files), $data['missing']);
        sort($reported);
        $expected = array_values(array_unique($paths));
        sort($expected);
        if ($reported !== $expected) {
            throw new RuntimeException('Node did not account for every requested file');
        }
        (new UpdatePlanner)->plan([], $files, []);
        ksort($files);

        return $files;
    }

    public function inventory(?array $paths = null): array
    {
        $payload = $this->operationId ? ['operation_id' => $this->operationId] : [];
        if ($paths !== null) {
            $capabilities = $this->worker->request('capabilities');
            if (($capabilities['scoped_inventory'] ?? null) !== 1) { throw new RuntimeException('Upgrade the filesystem worker: manifest-scoped comparison is required.'); }
            foreach ($paths as $path) { UpdatePlanner::assertPath($path); }
            $payload['paths'] = array_values(array_unique($paths));
        }
        $data = $this->worker->request('inventory', $payload);
        if ($paths !== null && (($data['scope'] ?? '') !== 'manifest-delta-v1' || ($data['requested_paths'] ?? null) !== $payload['paths'])) {
            throw new RuntimeException('Worker did not honor the exact manifest comparison scope');
        }
        if (($data['complete'] ?? false) !== true || ! is_array($data['files'] ?? null)) {
            throw new \Arcturus\ModpackManager\Services\PreparationException(['code' => 'METADATA_INVALID', 'phase' => 'comparing']);
        }
        if ($paths !== null) {
            if (array_diff(array_keys($data['files']), $payload['paths'])) { throw new RuntimeException('Worker returned files outside the requested delta'); }
            foreach ($payload['paths'] as $path) {
                if (isset($data['files'][$path]) || in_array($path, $data['missing'] ?? [], true)) { continue; }
                $excluded = false;
                foreach ($data['excluded_roots'] ?? [] as $root) { if ($path === $root || str_starts_with($path, $root.'/')) { $excluded = true; break; } }
                if (! $excluded) { throw new RuntimeException('Worker omitted a requested comparison path'); }
            }
        }
        (new UpdatePlanner)->plan([], $data['files'], []);

        return $data;
    }

    public function prepare(array $recipe, string $operationId): array
    {
        return $this->worker->request('prepare', ['recipe' => $recipe, 'operation_id' => $operationId]);
    }

    public function progress(string $operationId): array
    {
        return $this->worker->request('status', ['operation_id' => $operationId]);
    }

    public function cancel(string $operationId): array
    {
        return $this->worker->request('cancel', ['operation_id' => $operationId]);
    }
}
