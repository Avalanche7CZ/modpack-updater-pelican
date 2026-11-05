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

    public function state(Server $server): string
    {
        $details = $this->daemon->setServer($server)->getDetails(5);
        $state = $details['state'] ?? 'missing';
        if (!is_string($state) || $state === 'missing' || $state === 'offline') {
            throw new RuntimeException('Cannot confirm Wings container state');
        }
        return $state;
    }

    public function stop(Server $server, int $timeoutSeconds = 600): void
    {
        $state = $this->state($server);
        if ($state === 'exited') {
            return;
        }
        if ($state !== 'running') {
            throw new RuntimeException('Unsafe initial container state: ' . $state);
        }
        $this->daemon->setServer($server)->power('stop')->throw();
        $this->waitState($server, 'exited', $timeoutSeconds);
    }

    public function startAndVerify(Server $server, string $liveRoot, int $timeoutSeconds = 900): void
    {
        if ($this->state($server) !== 'exited') {
            throw new RuntimeException('Server is not stopped before start');
        }
        $log = rtrim($liveRoot, '/') . '/logs/latest.log';
        $beforeInode = is_file($log) ? @fileinode($log) : null;
        $offset = is_file($log) ? (int) @filesize($log) : 0;
        $this->daemon->setServer($server)->power('start')->throw();
        $deadline = microtime(true) + $timeoutSeconds;
        $seenRunning = false;
        while (microtime(true) < $deadline) {
            sleep(4);
            $state = $this->state($server);
            if ($state === 'exited' || $state === 'dead') {
                throw new RuntimeException('Minecraft process exited during startup');
            }
            if ($state === 'running') {
                $seenRunning = true;
            }
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

    private function waitState(Server $server, string $expected, int $seconds): void
    {
        $until = microtime(true) + $seconds;
        do {
            $state = $this->state($server);
            if ($state === $expected) {
                return;
            }
            sleep(3);
        } while (microtime(true) < $until);
        throw new RuntimeException("Timeout waiting for container state {$expected}");
    }
}
