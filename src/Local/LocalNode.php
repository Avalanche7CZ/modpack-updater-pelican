<?php

declare(strict_types=1);

namespace Arcturus\ModpackManager\Local;

use App\Models\Node;
use App\Models\Server;
use Arcturus\ModpackManager\Models\ManagedServer;
use Arcturus\ModpackManager\Remote\CapabilityUnavailable;

/** Host approval is read fresh, independently of Laravel's cached configuration. */
final class LocalNode
{
    public const CONFIG = '/etc/arcturus-modpack-manager/local-node.json';

    public function __construct(private readonly string $path = self::CONFIG, private readonly int $owner = 0) {}

    public function configuration(): array
    {
        clearstatcache(true, $this->path);
        if (! file_exists($this->path)) {
            throw new CapabilityUnavailable('Run the one-time Ubuntu node setup shown in Local filesystem setup. No per-server JSON or environment edits are required.');
        }
        if (is_link($this->path) || ! is_file($this->path) || ! is_readable($this->path) ||
            fileowner($this->path) !== $this->owner || (fileperms($this->path) & 0022) ||
            realpath($this->path) !== $this->path || fileowner(dirname($this->path)) !== $this->owner ||
            (fileperms(dirname($this->path)) & 0022) || filesize($this->path) > 65536) {
            throw new CapabilityUnavailable('Local node approval must be a regular root-owned file in a protected canonical directory, without group/world writes. Re-run host setup.');
        }
        try {
            $data = json_decode(file_get_contents($this->path), true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new CapabilityUnavailable('Local node approval is invalid. Re-run host setup.');
        }
        if (($data['schema'] ?? null) !== 1 || ! is_int($data['node_id'] ?? null) || $data['node_id'] < 1 ||
            ! is_string($data['node_uuid'] ?? null) || ! self::uuid($data['node_uuid']) || ! is_int($data['uid'] ?? null) || $data['uid'] < 1) {
            throw new CapabilityUnavailable('Local node approval has invalid node identity or service account. Re-run host setup.');
        }
        foreach (['volume_root', 'staging_parent'] as $key) {
            $path = $data[$key] ?? null;
            if (! is_string($path) || $path === '/' || ! str_starts_with($path, '/') || realpath($path) !== $path || ! is_dir($path)) {
                throw new CapabilityUnavailable('Approved local volume root or staging parent is missing, noncanonical or a symlink. Re-run host setup.');
            }
        }
        if (fileowner($data['volume_root']) !== $this->owner || (fileperms($data['volume_root']) & 0022)) {
            throw new CapabilityUnavailable('Approved Wings volume parent must remain operator-owned and not group/world writable. Re-run host setup.');
        }
        if (self::overlap($data['volume_root'], $data['staging_parent'])) {
            throw new CapabilityUnavailable('Local staging parent must be outside the approved Wings volume root.');
        }

        return $data;
    }

    public static function uuid(string $value): bool
    {
        return (bool) preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D', $value);
    }

    private static function overlap(string $a, string $b): bool
    {
        return $a === $b || str_starts_with($a, $b.'/') || str_starts_with($b, $a.'/');
    }

    public function node(): Node
    {
        return $this->verifiedNode($this->configuration());
    }

    private function verifiedNode(array $config): Node
    {
        $node = Node::find($config['node_id']);
        if (! $node || $node->uuid !== $config['node_uuid'] || $node->daemon_base !== $config['volume_root']) {
            throw new CapabilityUnavailable('Approved node identity or Wings volume root no longer matches Pelican metadata. Re-run host setup for the correct local node.');
        }

        return $node;
    }

    public function isLocal(string $uuid): bool
    {
        $node = $this->node();

        return Server::where('uuid', $uuid)->where('node_id', $node->id)->exists();
    }

    public function entry(string $uuid): array
    {
        $config = $this->configuration();
        $node = $this->verifiedNode($config);
        if (! self::uuid($uuid) || ! Server::where('uuid', $uuid)->where('node_id', $node->id)->exists()) {
            throw new CapabilityUnavailable('This server is not assigned to the approved local Wings node. Use its remote backend.');
        }
        $root = $config['volume_root'].'/'.$uuid;
        if (realpath($root) !== $root || ! is_dir($root) || ! is_readable($root) || ! is_executable($root)) {
            throw new CapabilityUnavailable('The real UUID volume is missing, a symlink, or unreadable. Check Wings provisioning and re-run node setup if existing ACLs were replaced.');
        }

        return ['root' => $root, 'staging' => $config['staging_parent'].'/'.$uuid,
            'allowed_hosts' => $config['allowed_hosts'] ?? [], 'artifacts' => $config['artifacts'] ?? []];
    }

    public function adoptionAttributes(string $uuid, string $selection): array
    {
        if ($selection === 'default') {
            if (config('arcturus-modpack-manager.default_filesystem_backend', 'wings') !== 'local') {
                return ['transport' => 'wings'];
            }
            $selection = $this->isLocal($uuid) ? 'local' : 'wings';
        }
        if ($selection === 'wings') {
            return ['transport' => 'wings'];
        }
        if ($selection !== 'local') {
            throw new CapabilityUnavailable('Select a supported filesystem backend.');
        }
        $entry = $this->entry($uuid);
        $candidate = new ManagedServer(['pelican_server_uuid' => $uuid, 'transport' => 'local']);
        (new LocalWorker($candidate))->request('provision');

        return ['transport' => 'local', 'local_root' => $entry['root'], 'staging_parent' => $entry['staging']];
    }

    /** A host-scoped queue setting replaces manual .env edits, for both web and queue boots. */
    public function configureQueue(): void
    {
        try {
            $this->configuration();
        } catch (CapabilityUnavailable) {
            return;
        }
        $key = 'queue.connections.'.config('queue.default');
        if (in_array(config($key.'.driver'), ['redis', 'database'], true)) {
            config([$key.'.retry_after' => max(14520, (int) config($key.'.retry_after', 0))]);
        }
    }

    public function status(): array
    {
        $report = ['ready' => false, 'checks' => [], 'servers' => []];
        try {
            $config = $this->configuration();
            $report['checks'][] = 'Root-owned host approval: valid';
            $node = $this->node();
            $report['checks'][] = 'Pelican node identity and volume root: matched';
            LocalWorker::execute(['operation' => 'node_capabilities', 'node' => $config], 10);
            $report['checks'][] = 'Unprivileged Python, volume access and private staging parent: ready';
            $this->configureQueue();
            try {
                app(\Arcturus\ModpackManager\Services\QueuePreparation::class)->requireLocalQueue();
            } catch (\RuntimeException $error) {
                throw new CapabilityUnavailable($error->getMessage());
            }
            $report['checks'][] = 'Asynchronous queue and retry visibility: ready (restart workers after setup/import)';
            $report['ready'] = true;
            foreach (Server::where('node_id', $node->id)->orderBy('name')->get(['uuid', 'name']) as $server) {
                try {
                    $entry = $this->entry($server->uuid);
                    if (file_exists($entry['staging']) || is_link($entry['staging'])) {
                        if (realpath($entry['staging']) !== $entry['staging'] || ! is_dir($entry['staging']) ||
                            fileowner($entry['staging']) !== $config['uid'] || (fileperms($entry['staging']) & 0077) || ! is_writable($entry['staging'])) {
                            throw new CapabilityUnavailable('UUID staging has unsafe ownership, mode or path. Inspect it; the web UI will not repair existing permissions.');
                        }
                        $state = 'Volume and private staging: ready';
                    } else {
                        $state = 'Volume eligible; private staging created on adoption';
                    }
                } catch (CapabilityUnavailable $error) {
                    $state = $error->getMessage();
                }
                $report['servers'][] = ['uuid' => $server->uuid, 'name' => $server->name, 'state' => $state];
            }
        } catch (CapabilityUnavailable $error) {
            $report['checks'][] = $error->getMessage();
        } catch (\Throwable) {
            $report['checks'][] = 'Pelican node/server metadata is unavailable. Check Panel database connectivity.';
        }

        return $report;
    }
}
