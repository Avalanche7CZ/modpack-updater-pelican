<?php

declare(strict_types=1);
namespace Arcturus\ModpackManager\Native;

use App\Models\Server;
use App\Repositories\Daemon\DaemonServerRepository;
use Arcturus\ModpackManager\Services\PelicanPowerController;
use Arcturus\ModpackManager\Services\Pelican\GuardedDaemonServerRepository;
use Arcturus\ModpackManager\Services\RuntimeInspector;
use RuntimeException;

final class Boot
{
    public function start(Server $server, Wings $files, string $id, array $target, \Closure $journal, ?\Closure $heartbeat = null): array
    {
        if (! preg_match('/^[a-z0-9-]{32,50}$/D', $id)) { throw new RuntimeException('Invalid startup checkpoint identity.'); }
        $power = app(PelicanPowerController::class);
        if ($power->state($server) !== 'exited') { throw new RuntimeException('Native startup verification requires the container stopped.'); }
        $log = 'logs/latest.log'; $saved = 'logs/.arcturus-before-'.$id.'.log';
        if ($files->stat($saved) !== null) { throw new RuntimeException('A previous startup checkpoint exists; do not replay start.'); }
        $journal(['step' => 'boot_log_retire_intent', 'saved' => $saved]);
        if ($files->stat($log) !== null) { $files->rename($log, $saved); }
        if ($files->stat($log) !== null) { throw new RuntimeException('Old startup log was not retired.'); }
        $journal(['step' => 'boot_start_intent']);
        GuardedDaemonServerRepository::controlledStart($server->uuid, fn () => app(DaemonServerRepository::class)->setServer($server)->power('start')->throw());
        $deadline = microtime(true) + (int) config('arcturus-modpack-manager.deployment_start_timeout', 900);
        while (microtime(true) < $deadline) {
            $heartbeat?->__invoke(); sleep(1);
            $state = $power->state($server);
            if (in_array($state, ['exited', 'dead'], true)) { throw new RuntimeException('Minecraft exited during native startup verification.'); }
            if ($files->stat($log) === null) { continue; }
            try { $text = $files->read($log, 4194304); }
            catch (TextChangedDuringInspection) {
                // Minecraft is writing this log. Discard the read and retry on the
                // next poll, within the same deadline and after checking power again.
                continue;
            }
            if ($state !== 'running' || ! preg_match('/\bDone \([\d.]+s\)!/i', $text)) { continue; }
            $observed = (new RuntimeInspector)->fromStartupLog($text);
            foreach (['minecraft', 'loader', 'loader_version', 'java'] as $key) {
                if (isset($target[$key]) && $observed[$key] !== $target[$key]) { throw new RuntimeException('Fresh Minecraft boot did not confirm target '.$key.'. Keep this runtime blocked until its boot evidence can be verified.'); }
            }
            $journal(['step' => 'boot_verified', 'runtime' => $observed]);
            return [...$target, ...$observed, 'java_confidence' => 'verified'];
        }
        throw new RuntimeException('Minecraft did not produce a fresh Done marker before timeout.');
    }
}
