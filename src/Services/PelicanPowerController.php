<?php
declare(strict_types=1);

namespace Arcturus\ModpackManager\Services;

use App\Models\Server;
use App\Repositories\Daemon\DaemonServerRepository;
use RuntimeException;

/**
 * Pelican/Wings control. Uses the same daemon repository as Pelican's own
 * Client Power API, and checks the actual daemon state instead of trusting
 * a cached control-panel indicator.
 *
 * This adapter is deliberately tied to Pelican 1.0's internal API and requires
 * integration testing on the operator's exact panel version.
 */
final class PelicanPowerController
{
    public function __construct(private readonly DaemonServerRepository $daemon) {}

    public function details(Server $server): array
    {
        return $this->daemon->setServer($server)->getDetails(5);
    }

    /** Confirm daemon acknowledgement, not merely a successful sync request. */
    public function deploymentGuard(Server $server, bool $allowStart): void
    {
        $this->sync($server);
        $deadline = microtime(true) + max(1, (int) config('arcturus-modpack-manager.deployment_guard_timeout', 15));
        do {
            $details = $this->details($server);
            if (($details['configuration']['crash_detection_enabled'] ?? null) === false &&
                ($details['configuration']['suspended'] ?? null) === ! $allowStart) { return; }
            sleep(1); // Wings may finish its Panel configuration refresh after /sync returns.
        } while (microtime(true) < $deadline);
        throw new RuntimeException('Wings did not acknowledge the per-server deployment guard. Restart Panel/queue workers after plugin upgrade and verify its configuration service binding. Do not deploy without the guard.');
    }

    /** Stop is attempted even when guard acknowledgement fails. Never continue to writes on that failure. */
    public function guardAndStop(Server $server, ?\Closure $heartbeat = null): void
    {
        try { $this->deploymentGuard($server, false); }
        finally { $this->stop($server, heartbeat: $heartbeat); }
    }

    public function sync(Server $server): void
    {
        $this->daemon->setServer($server)->getHttpClient()->connectTimeout(5)->timeout(15)
            ->post('/api/servers/'.$server->uuid.'/sync')->throw();
    }

    public function state(Server $server): string
    {
        $details = $this->details($server);
        $state = $details['state'] ?? 'missing';
        if ($state === 'offline') { return 'exited'; }
        if (!is_string($state) || $state === 'missing') {
            throw new RuntimeException('Cannot confirm Wings container state');
        }
        return $state;
    }

    public function stop(Server $server, int $timeoutSeconds = 600, ?\Closure $heartbeat = null): void
    {
        $state = $this->state($server);
        if ($state === 'exited') {
            return;
        }
        if ($state === 'stopping') { $this->waitState($server, 'exited', $timeoutSeconds, $heartbeat); return; }
        if (! in_array($state, ['running', 'starting'], true)) {
            throw new RuntimeException('Unsafe initial container state: ' . $state);
        }
        $this->daemon->setServer($server)->power('stop')->throw();
        $this->waitState($server, 'exited', $timeoutSeconds, $heartbeat);
    }

    public function startAndVerify(Server $server, string $liveRoot, int $timeoutSeconds = 900, ?\Closure $heartbeat = null): void
    {
        if ($this->state($server) !== 'exited') {
            throw new RuntimeException('Server is not stopped before start');
        }
        $log = rtrim($liveRoot, '/') . '/logs/latest.log';
        if (is_link(dirname($log)) || is_link($log)) { throw new RuntimeException('Startup log cannot be a symlink'); }
        $beforeInode = is_file($log) ? @fileinode($log) : null;
        $offset = is_file($log) ? (int) @filesize($log) : 0;
        $this->daemon->setServer($server)->power('start')->throw();
        $deadline = microtime(true) + $timeoutSeconds;
        $seenRunning = false;
        while (microtime(true) < $deadline) {
            $heartbeat?->__invoke();
            sleep(1);
            $state = $this->state($server);
            if ($state === 'exited' || $state === 'dead') {
                throw new RuntimeException('Minecraft process exited during startup');
            }
            if ($state === 'running') {
                $seenRunning = true;
            }
            if (is_link(dirname($log)) || is_link($log)) { throw new RuntimeException('Startup log changed into a symlink'); }
            if (!is_file($log)) {
                continue;
            }
            clearstatcache(true, $log);
            $inode = @fileinode($log);
            $size = @filesize($log);
            if ($size === false) {
                continue;
            }
            if ($beforeInode !== $inode || $size < $offset) {
                $offset = 0; // rotated log on server restart
                $beforeInode = $inode;
            }
            $fp = @fopen($log, 'rb');
            if ($fp === false) {
                continue;
            }
            fseek($fp, $offset);
            $chunk = stream_get_contents($fp, 1048576);
            $offset = ftell($fp);
            fclose($fp);
            if ($seenRunning && is_string($chunk) &&
                preg_match('/\bDone \([\d.]+s\)!/i', $chunk) === 1) {
                return;
            }
        }
        throw new RuntimeException('Minecraft did not reach the Done startup marker before timeout');
    }

    private function waitState(Server $server, string $expected, int $seconds, ?\Closure $heartbeat = null): void
    {
        $until = microtime(true) + $seconds;
        do {
            $state = $this->state($server);
            if ($state === $expected) {
                return;
            }
            $heartbeat?->__invoke();
            sleep(1);
        } while (microtime(true) < $until);
        throw new RuntimeException("Timeout waiting for container state {$expected}");
    }
}
