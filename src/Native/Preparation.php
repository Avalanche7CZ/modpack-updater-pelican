<?php

declare(strict_types=1);
namespace Arcturus\ModpackManager\Native;

use Arcturus\ModpackManager\Contracts\IntegrityWorker;
use Arcturus\ModpackManager\Local\LocalWorker;
use Arcturus\ModpackManager\Models\ManagedServer;
use Arcturus\ModpackManager\Services\DirectoryPolicy;
use Arcturus\ModpackManager\Services\SafetyPaths;
use Arcturus\ModpackManager\Services\UpdatePlanner;
use Arcturus\ModpackManager\Security\ManagedPaths;
use RuntimeException;

/** Panel-private upstream preparation plus native Wings candidate inspection. */
final class Preparation implements IntegrityWorker
{
    private ?\Closure $heartbeat = null;
    private ?\Closure $progress = null;
    public function __construct(private readonly ManagedServer $server) {}
    public function withHeartbeat(\Closure $value): self { $this->heartbeat = $value; return $this; }
    public function withProgress(\Closure $value): self { $this->progress = $value; return $this; }

    /** Only Panel storage, never the Wings volume. Fixed worker cannot execute a shell. */
    public function configuration(): array
    {
        $uuid = $this->server->pelican_server_uuid;
        if (! \Illuminate\Support\Str::isUuid($uuid)) { throw new RuntimeException('Invalid Pelican server UUID.'); }
        $parent = realpath(storage_path('app'));
        if ($parent === false) { throw new RuntimeException('Panel storage must be canonical for private preparation.'); }
        $root = $parent.'/arcturus-modpack-manager/native/'.$uuid;
        foreach (['arcturus-modpack-manager', 'native', $uuid, 'inspection', 'staging'] as $part) {
            // inspection and staging are siblings.
            if (in_array($part, ['inspection', 'staging'], true)) { $path = $root.'/'.$part; }
            else { $parent .= '/'.$part; $path = $parent; }
            if (is_link($path) || (file_exists($path) && ! is_dir($path))) { throw new RuntimeException('Private preparation path is unsafe.'); }
            if (! is_dir($path) && ! mkdir($path, 0700)) { throw new RuntimeException('Panel queue cannot create its private preparation directory.'); }
        }
        foreach ([$root, $root.'/inspection', $root.'/staging'] as $path) {
            if (realpath($path) !== $path || fileperms($path) & 0077 || fileowner($path) !== posix_geteuid()) { throw new RuntimeException('Private preparation directories must be owned by the unprivileged Panel queue account with mode 0700.'); }
        }
        return [$uuid => ['root' => $root.'/inspection', 'staging' => $root.'/staging', 'allowed_hosts' => config('arcturus-modpack-manager.allowed_custom_hosts', [])]];
    }

    public function stage(string $id, string $manifestSha256): string
    {
        if (! preg_match('/^[a-f0-9]{32}$/D', $id) || ! preg_match('/^[a-f0-9]{64}$/D', $manifestSha256)) { throw new RuntimeException('Native preparation identity is invalid.'); }
        $entry = $this->configuration()[$this->server->pelican_server_uuid]; $root = $entry['staging'].'/'.$id;
        foreach ([$root, $root.'/files', $root.'/operation.json', $root.'/manifest.json'] as $path) {
            if (is_link($path) || realpath($path) !== $path) { throw new RuntimeException('Retained native preparation is missing or unsafe; prepare the target again.'); }
        }
        $status = json_decode(file_get_contents($root.'/operation.json'), true, flags: JSON_THROW_ON_ERROR);
        if (($status['state'] ?? '') !== 'completed' || ($status['manifest_sha256'] ?? '') !== $manifestSha256 || hash_file('sha256', $root.'/manifest.json') !== $manifestSha256) {
            throw new RuntimeException('Native stage no longer matches its verified preparation receipt.');
        }
        return $root.'/files';
    }

    public function request(string $operation, array $payload = []): array
    {
        if ($operation === 'inventory' || $operation === 'hashes') { return $this->inventory($payload, $operation === 'hashes'); }
        if (! in_array($operation, ['capabilities', 'prepare', 'resolve', 'status', 'cancel', 'manifest', 'stage_file', 'stage_mod_metadata', 'requirements'], true)) { throw new RuntimeException('Unsupported native preparation operation.'); }
        $data = LocalWorker::execute(['servers' => $this->configuration(), 'server' => $this->server->pelican_server_uuid, 'operation' => $operation,
            'payload' => [...$payload, 'directory_scope' => (new DirectoryPolicy)->scope($this->server)]],
            in_array($operation, ['prepare', 'resolve'], true) ? LocalWorker::TIMEOUT : 120,
            $operation === 'cancel' ? null : $this->heartbeat, $operation === 'cancel' ? null : $this->progress);
        if ($operation === 'capabilities') { $data = [...$data, 'backend' => 'native', 'scoped_inventory' => 1, 'deploy' => false]; }
        return $data;
    }

    private function inventory(array $payload, bool $hashesOnly): array
    {
        if (! isset($payload['paths']) || ! is_array($payload['paths']) || ! array_is_list($payload['paths']) || count($payload['paths']) > 100000) { throw new RuntimeException('Native inventory requires an explicit bounded manifest delta.'); }
        $paths = array_values(array_unique($payload['paths']));
        $files = new Wings($this->server->pelicanServer, app(\App\Repositories\Daemon\DaemonFileRepository::class), $this->heartbeat);
        $scope = (new DirectoryPolicy)->scope($this->server);
        $properties = $files->read('server.properties', 1048576);
        $excluded = array_values(array_unique([...SafetyPaths::roots($properties), ...$scope['exclude']]));
        $result = []; $missing = [];
        foreach ($paths as $path) {
            UpdatePlanner::assertPath($path);
            if (SafetyPaths::reason($path, $excluded) !== null || ! ManagedPaths::allowed($path, $scope['manage'])) { $excluded[] = $path; continue; }
            $hash = $files->hash($path); $this->heartbeat?->__invoke();
            if ($hash === null) { $missing[] = $path; } else { $result[$path] = $hash; }
        }
        ksort($result);
        return ['complete' => true, 'files' => $result, 'missing' => $missing, 'identities' => [], 'metadata' => [],
            'scope' => 'manifest-delta-v1', 'requested_paths' => $paths, 'excluded_roots' => array_values(array_unique($excluded)),
            'skipped_symlinks' => [], 'unreadable_paths' => [], 'world_path_unreadable' => false,
            'properties_sha256' => hash('sha256', $properties),
            'inventory_summary' => ['files_hashed' => count($result), 'unreadable_files_skipped' => 0, 'unreadable_directories' => 0, 'unreadable_entries_unknown' => 0]];
    }
}
