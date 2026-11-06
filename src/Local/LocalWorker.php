<?php

declare(strict_types=1);

namespace Arcturus\ModpackManager\Local;

use Arcturus\ModpackManager\Contracts\IntegrityWorker;
use Arcturus\ModpackManager\Models\ManagedServer;
use Arcturus\ModpackManager\Remote\CapabilityUnavailable;
use Symfony\Component\Process\Process;

/** Fixed, unprivileged subprocess; no shell, HTTP listener, tokens or live writes. */
final class LocalWorker implements IntegrityWorker
{
    public const TIMEOUT = 14400;

    private ?\Closure $heartbeat = null;

    public function withHeartbeat(\Closure $heartbeat): self
    {
        $this->heartbeat = $heartbeat;

        return $this;
    }

    public function __construct(private readonly ManagedServer $server) {}

    public function configuration(): array
    {
        if (config('arcturus-modpack-manager.local_config_error')) {
            throw new CapabilityUnavailable('Local filesystem configuration cannot be loaded. Check ARCTURUS_MODPACK_MANAGER_LOCAL_CONFIG: readable JSON file, no symlink or group/world writes. Rebuild config cache and restart pelican-queue.');
        }
        $entries = config('arcturus-modpack-manager.local_servers', []);
        $entry = $entries[$this->server->pelican_server_uuid] ?? null;
        if ($entry === null) {
            $entry = app(LocalNode::class)->entry($this->server->pelican_server_uuid);
            $entries = [$this->server->pelican_server_uuid => $entry];
        }
        if (! is_array($entry) || ! is_string($entry['root'] ?? null) || ! is_string($entry['staging'] ?? null)) {
            throw new CapabilityUnavailable('Local filesystem is not configured for this server UUID. Add root and staging to ARCTURUS_MODPACK_MANAGER_LOCAL_CONFIG, rebuild config cache and restart pelican-queue. See docs/local-filesystem.md.');
        }
        if (($this->server->local_root && $this->server->local_root !== $entry['root']) ||
            ($this->server->staging_parent && $this->server->staging_parent !== $entry['staging'])) {
            throw new CapabilityUnavailable('Saved local paths differ from the operator mapping. Re-select the local backend to validate the new paths.');
        }

        return $entries;
    }

    public function request(string $operation, array $payload = []): array
    {
        if (! in_array($operation, ['provision', 'capabilities', 'hashes', 'inventory', 'prepare', 'status', 'cancel', 'manifest', 'stage_file', 'requirements', 'resolve'], true)) {
            throw new CapabilityUnavailable('Unsupported local capability');
        }
        $entries = $this->configuration();

        return self::execute(['servers' => $entries, 'server' => $this->server->pelican_server_uuid,
            'operation' => $operation, 'payload' => $payload],
            in_array($operation, ['prepare', 'resolve'], true) ? self::TIMEOUT : 120, $this->heartbeat);
    }

    public static function execute(array $request, int $timeout, ?\Closure $heartbeat = null): array
    {
        $process = new Process(['/usr/bin/python3', '-B', dirname(__DIR__, 2).'/node-worker/local.py']);
        $process->setInput(json_encode($request, JSON_THROW_ON_ERROR));
        $process->setTimeout($timeout);
        $output = '';
        try {
            $process->start(function (string $type, string $chunk) use (&$output, $process): void {
                if ($type === Process::OUT) {
                    $output .= $chunk;
                    if (strlen($output) > 33554432) {
                        $process->stop(0);
                        throw new CapabilityUnavailable('Local metadata exceeds the 32 MiB response limit');
                    }
                }
                // Process buffers are cleared; stderr is never rendered (could contain recipe URLs).
                $process->clearOutput();
                $process->clearErrorOutput();
            });
            $lastHeartbeat = 0;
            while ($process->isRunning()) {
                $process->checkTimeout();
                if ($heartbeat && time() - $lastHeartbeat >= 30) {
                    $heartbeat();
                    $lastHeartbeat = time();
                }
                usleep(100000);
            }
            $process->wait();
        } catch (CapabilityUnavailable $error) {
            $process->stop(0);
            throw $error;
        } catch (\Throwable) {
            $process->stop(0);
            throw new CapabilityUnavailable('Local helper interrupted or unavailable. Install Python 3.11+, allow PHP process execution, and inspect retained staging state before retrying.');
        }
        $data = json_decode($output, true);
        if (! $process->isSuccessful() || ! is_array($data)) {
            // Only fixed error codes cross the subprocess boundary; never raw exception text.
            $message = match ($data['code'] ?? '') {
                'permissions' => 'Local filesystem permission check failed. Run PHP-FPM and pelican-queue as an unprivileged account with read/traverse access to this volume and ownership of mode-0700 staging. Never use root or chmod 777.',
                'paths' => 'Local paths must be canonical existing directories without symlinks. Staging must be private, outside all live volumes, and separate for every server.',
                'busy' => 'Another local preparation is active. Inspect the current operation before retrying.',
                'runtime' => 'Local preparation requires Python 3.11+ and an unprivileged Panel/queue account. Root execution is refused.',
                default => 'Local operation rejected by filesystem, archive or integrity validation. Check configured paths and retained operation state; no live files were changed.',
            };
            throw new CapabilityUnavailable($message);
        }

        return $data;
    }
}
