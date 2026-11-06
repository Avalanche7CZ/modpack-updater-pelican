<?php

declare(strict_types=1);

namespace Arcturus\ModpackManager\Local;

use Arcturus\ModpackManager\Contracts\IntegrityWorker;
use Arcturus\ModpackManager\Models\ManagedServer;
use Arcturus\ModpackManager\Remote\CapabilityUnavailable;
use Symfony\Component\Process\Process;
use Arcturus\ModpackManager\Services\PreparationException;

/** Fixed, unprivileged subprocess; no shell, HTTP listener, tokens or live writes. */
final class LocalWorker implements IntegrityWorker
{
    public const TIMEOUT = 14400;

    private ?\Closure $heartbeat = null;
    private ?\Closure $progress = null;

    public function withProgress(\Closure $progress): self
    {
        $this->progress = $progress;
        return $this;
    }

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
            throw new CapabilityUnavailable('The legacy per-server filesystem override is invalid. Repair or retire that override, then use Local filesystem setup for node-wide discovery.');
        }
        if (($this->server->local_root && $this->server->local_root !== $entry['root']) ||
            ($this->server->staging_parent && $this->server->staging_parent !== $entry['staging'])) {
            throw new CapabilityUnavailable('Saved local paths differ from the approved configuration. Re-select the local backend to validate and pin the new paths.');
        }

        return $entries;
    }

    public function request(string $operation, array $payload = []): array
    {
        if (! in_array($operation, ['provision', 'capabilities', 'hashes', 'inventory', 'prepare', 'status', 'cancel', 'manifest', 'stage_file', 'requirements', 'resolve'], true)) {
            throw new CapabilityUnavailable('Unsupported local capability');
        }
        $entries = $this->configuration();
        $payload['directory_scope'] = (new \Arcturus\ModpackManager\Services\DirectoryPolicy)->scope($this->server);

        return self::execute(['servers' => $entries, 'server' => $this->server->pelican_server_uuid,
            'operation' => $operation, 'payload' => $payload],
            (in_array($operation, ['prepare', 'resolve'], true) || ($operation === 'inventory' && $this->progress !== null)) ? self::TIMEOUT : 120,
            $operation === 'cancel' ? null : $this->heartbeat, $operation === 'cancel' ? null : $this->progress);
    }

    public static function execute(array $request, int $timeout, ?\Closure $heartbeat = null, ?\Closure $progress = null): array
    {
        $streaming = $progress !== null;
        $request['progress_stream'] = $streaming;
        $process = new Process(['/usr/bin/python3', '-B', dirname(__DIR__, 2).'/node-worker/local.py']);
        $process->setInput(json_encode($request, JSON_THROW_ON_ERROR));
        $process->setTimeout($timeout);
        $output = '';
        $result = null;
        $lastPhase = ($request['operation'] ?? '') === 'inventory' ? 'comparing' : 'queued';
        try {
            $process->start(function (string $type, string $chunk) use (&$output, &$result, &$lastPhase, $process, $streaming, $progress): void {
                if ($type === Process::OUT) {
                    $output .= $chunk;
                    if (strlen($output) > 33554432) {
                        $process->stop(0);
                        throw new CapabilityUnavailable('Local metadata exceeds the 32 MiB response limit');
                    }
                    if ($streaming) {
                        while (($position = strpos($output, "\n")) !== false) {
                            $line = substr($output, 0, $position);
                            $output = substr($output, $position + 1);
                            $frame = json_decode($line, true);
                            if (! is_array($frame)) { throw new PreparationException(['code' => 'METADATA_INVALID']); }
                            if (($frame['type'] ?? '') === 'progress' && $result === null) {
                                $lastPhase = $frame['progress']['phase'] ?? $lastPhase;
                                $progress($frame);
                            }
                            elseif (($frame['type'] ?? '') === 'result' && $result === null && is_array($frame['data'] ?? null)) { $result = $frame['data']; }
                            else { throw new PreparationException(['code' => 'METADATA_INVALID']); }
                        }
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
        } catch (PreparationException|CapabilityUnavailable $error) {
            $process->stop(0);
            throw $error;
        } catch (\Throwable $error) {
            $process->stop(0);
            throw new PreparationException(['code' => $error instanceof \Symfony\Component\Process\Exception\ProcessTimedOutException ? 'LOCAL_TIMEOUT' : 'LOCAL_INTERRUPTED', 'phase' => $lastPhase]);
        }
        $data = $streaming ? $result : json_decode($output, true);
        if ($streaming && trim($output) !== '') { throw new PreparationException(['code' => 'METADATA_INVALID']); }
        if (! is_array($data)) { throw new PreparationException(['code' => 'METADATA_INVALID', 'phase' => $lastPhase]); }
        if (! $process->isSuccessful()) {
            if (isset($data['diagnostic']) && ! in_array($request['operation'], ['capabilities', 'node_capabilities', 'provision'], true)) {
                throw new PreparationException($data['diagnostic']);
            }
            // Only fixed error codes cross the subprocess boundary; never raw exception text.
            $message = match ($data['code'] ?? '') {
                'permissions' => 'Local filesystem permission check failed. Re-run the one-time node setup as an administrator and verify PHP-FPM and pelican-queue use the same unprivileged account. The Panel will not change existing permissions. Never use root PHP or chmod 777.',
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
