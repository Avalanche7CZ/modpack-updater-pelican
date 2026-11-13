<?php

declare(strict_types=1);
namespace Arcturus\ModpackManager\Native;

use App\Models\Server;
use App\Repositories\Daemon\DaemonServerRepository;
use Arcturus\ModpackManager\Services\PelicanPowerController;
use Arcturus\ModpackManager\Services\Pelican\GuardedDaemonServerRepository;
use Arcturus\ModpackManager\Services\RuntimeInspector;
use RuntimeException;
use Throwable;

final class Boot
{
    public function start(Server $server, Wings $files, string $id, array $target, \Closure $journal, ?\Closure $heartbeat = null, ?\Closure $onFailure = null): array
    {
        if (! preg_match('/^[a-z0-9-]{32,50}$/D', $id)) { throw new RuntimeException('Invalid startup checkpoint identity.'); }
        if (empty($target['minecraft']) || empty($target['loader']) || !is_int($target['java'] ?? null) || $target['java'] < 1) { throw new RuntimeException('Startup requires a known expected Minecraft, loader and Java runtime.'); }
        $power = app(PelicanPowerController::class);
        if ($power->state($server) !== 'exited') { throw new RuntimeException('Native startup verification requires the container stopped.'); }
        $log = 'logs/latest.log'; $saved = 'logs/.arcturus-before-'.$id.'.log';
        if ($files->stat($saved) !== null) { throw new RuntimeException('A previous startup checkpoint exists; do not replay start.'); }
        $journal(['step' => 'boot_log_retire_intent', 'saved' => $saved]);
        if ($files->stat($log) !== null) { $files->rename($log, $saved); }
        if ($files->stat($log) !== null) { throw new RuntimeException('Old startup log was not retired.'); }
        $retiredHash = $files->hash($saved);
        $capture = $onFailure === null ? null : new BootFailureEvidence($files);
        $ackSeconds = max(1, (int) config('arcturus-modpack-manager.deployment_start_ack_timeout', 60));
        $proof = ['id' => $id, 'requested_at' => microtime(true), 'log_absent' => true, 'saved' => $saved, 'target' => $target, 'ack_seconds' => $ackSeconds];
        $journal(['step' => 'boot_start_intent', 'boot_attempt' => $proof]);
        try {
            GuardedDaemonServerRepository::controlledStart($server->uuid, fn () => app(DaemonServerRepository::class)->setServer($server)->power('start')->throw());
            $proof['accepted_at'] = microtime(true);
            $journal(['step' => 'boot_start_accepted', 'boot_attempt' => $proof]);
            $seenStart = false; $staleSeen = false; $instance = null;
            $deadline = microtime(true) + (int) config('arcturus-modpack-manager.deployment_start_timeout', 900);
            while (microtime(true) < $deadline) {
                $heartbeat?->__invoke(); sleep(1);
                $queriedAt = microtime(true); $details = $power->details($server); $receivedAt = microtime(true);
                $state = $details['state'] ?? 'missing';
                if ($state === 'starting') { $seenStart = true; }
                if (in_array($state, ['offline', 'exited', 'dead'], true) && $seenStart) { throw new RuntimeException('Minecraft exited during native startup verification after a new start was observed.'); }
                if ($state === 'running') {
                    try {
                        $sample = RunningInstance::sample($details, $queriedAt, $receivedAt);
                        $instance = RunningInstance::same($instance ?? ['min' => $proof['requested_at'] - 0.01, 'max' => $proof['accepted_at'] + $ackSeconds], $sample);
                        $seenStart = true; $proof['instance'] = $instance;
                    } catch (RuntimeException $identityError) {
                        if ($instance !== null) { throw $identityError; }
                        if ($receivedAt >= $proof['accepted_at'] + $ackSeconds) { throw new RuntimeException('Wings did not expose a verifiable new running instance before acknowledgement timeout.'); }
                        continue; // A cached old/zero uptime sample cannot establish the new start.
                    }
                }
                if (!$seenStart && $receivedAt >= $proof['accepted_at'] + $ackSeconds) { throw new RuntimeException('Wings never observed the requested start before the acknowledgement timeout.'); }
                if ($state === 'running') { $capture?->observedRunning(); }
                if ($files->stat($log) === null) { continue; }
                try { $logEvidence = $files->bootLogEvidence(); $text = $logEvidence['text']; }
                catch (TextChangedDuringInspection) {
                    // Discard the raced read and poll again within the same deadline.
                    continue;
                }
                $capture?->sample($text);
                if ($retiredHash !== null && $logEvidence['sha256'] === $retiredHash) { $staleSeen = true; continue; }
                if ($state !== 'running' || !$logEvidence['done']) { continue; }
                $observed = (new RuntimeInspector)->fromStartupLog($text);
                foreach (['minecraft', 'loader', 'loader_version', 'java'] as $key) {
                    if (isset($target[$key]) && $observed[$key] !== $target[$key]) { throw new RuntimeException('Fresh Minecraft boot did not confirm target '.$key.'. Keep this runtime blocked until its boot evidence can be verified.'); }
                }
                $lastQuery = microtime(true); $lastDetails = $power->details($server);
                $instance = RunningInstance::same($instance, RunningInstance::sample($lastDetails, $lastQuery, microtime(true)));
                $proof['instance'] = $instance; $proof['log_sha256'] = $logEvidence['sha256'];
                $journal(['step' => 'boot_verified', 'runtime' => $observed, 'boot_attempt' => $proof]);
                return [...$target, ...$observed, 'java_confidence' => 'verified'];
            }
            throw new RuntimeException($seenStart ? ($staleSeen ? 'Only retired log bytes were observed; startup freshness remains unproven.' : 'Minecraft did not produce a fresh Done marker before timeout.') : 'Wings never observed the requested start before timeout.');
        } catch (Throwable $error) {
            $proof['failure'] = ($seenStart ?? false) ? 'startup_failed_after_observation' : 'start_not_observed';
            try { $journal(['step' => 'boot_verification_failed', 'boot_attempt' => $proof, 'failure' => $proof['failure']]); } catch (Throwable $recordError) { report($recordError); }
            // Diagnostics must never mask the boot error or prevent rollback.
            if ($capture !== null) {
                try { $onFailure($capture->collect()); } catch (Throwable) { }
            }
            throw $error;
        }
    }
}
