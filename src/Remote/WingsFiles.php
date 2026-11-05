<?php

declare(strict_types=1);

namespace Arcturus\ModpackManager\Remote;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use Arcturus\ModpackManager\Contracts\ServerFiles;
use Arcturus\ModpackManager\Services\UpdatePlanner;
use RuntimeException;

/** Native Pelican file API. Integrity and safe archive operations require the node worker. */
final class WingsFiles implements ServerFiles
{
    public function __construct(private readonly Server $server,
        private readonly DaemonFileRepository $daemon, private readonly NodeWorker $worker) {}

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
        $rows = $this->repo()->getDirectory($directory === '' ? '/' : $this->path($directory));
        if (! array_is_list($rows)) {
            throw new RuntimeException('Invalid Wings directory listing');
        }
        foreach ($rows as $row) {
            if (! is_array($row) || ! is_string($row['name'] ?? null) || str_contains($row['name'], '/')) {
                throw new RuntimeException('Invalid Wings file metadata');
            }
            UpdatePlanner::assertPath($row['name']);
        }

        return $rows;
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

    public function inventory(): array
    {
        $data = $this->worker->request('inventory');
        if (($data['complete'] ?? false) !== true || ! is_array($data['files'] ?? null)) {
            throw new RuntimeException('Remote managed-file inventory is incomplete');
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
