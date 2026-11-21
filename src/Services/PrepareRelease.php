<?php

declare(strict_types=1);

namespace Arcturus\ModpackManager\Services;

use Arcturus\ModpackManager\Contracts\RequiredFileResolver;
use Arcturus\ModpackManager\Models\ManagedServer;
use Arcturus\ModpackManager\Models\Operation;
use Arcturus\ModpackManager\Modpacks\ProviderRegistry;
use Arcturus\ModpackManager\Modpacks\Providers\GtnhArtifact;
use Arcturus\ModpackManager\Remote\ServerFilesFactory;
use Arcturus\ModpackManager\Local\LocalWorker;
use RuntimeException;

/** Remote polling or a bounded local subprocess; artifacts never enter PHP memory. */
final class PrepareRelease
{
    public function __construct(private readonly ProviderRegistry $providers,
        private readonly OperationTracker $tracker, private readonly ReleaseStore $store) {}

    public function tick(ManagedServer $server, Operation $operation): bool
    {
        $context = $operation->context;
        if ($context['provider'] !== $server->provider || $context['source'] !== $server->source ||
            ($operation->kind === 'baseline' ? $server->installed_version : $server->targetVersion()) !== $context['version']) {
            throw new RuntimeException('Server source or version changed during preparation; operation cancelled');
        }
        if ($operation->created_at->lt(now()->subHours(4))) {
            throw new RuntimeException('Preparation deadline exceeded; retain node stage and inspect before retrying');
        }
        $worker = app(ServerFilesFactory::class)->worker($server);
        if ($worker instanceof LocalWorker || $worker instanceof \Arcturus\ModpackManager\Native\Preparation) {
            $worker->withProgress(fn (array $snapshot) => PreparationProgress::record($operation, $snapshot));
            $worker->withHeartbeat(function () use ($operation, $server): void {
                $operation->update(['heartbeat_at' => now()]);
                if (Operation::whereKey($operation->id)->value('cancel_requested_at')) {
                    try { app(ServerFilesFactory::class)->worker($server)->request('cancel', ['operation_id' => $operation->node_operation_id]); }
                    catch (\Throwable) { /* Retain pending intent; cancellation is not confirmed by a lost response. */ }
                }
            });
        }
        $trust = app(GtnhArchiveTrust::class);
        if ($operation->phase === 'queued') {
            $release = $this->providers->get($server->provider)->release($server->source, $context['version'], $server->asset_pattern);
            if (! $release->canPrepare() && ! $release->canInspectArchive()) {
                $missing = count(array_filter($release->downloads, fn ($d) => empty($d['hashes']))) > 0;
                throw new PreparationException(['code' => $missing ? 'CHECKSUM_MISSING' : 'RELEASE_UNVERIFIED', 'phase' => 'queued']);
            }
            if ($release->format === 'curseforge' && ! ($worker->request('capabilities')['curseforge_resolution'] ?? false)) {
                throw new RuntimeException('This node worker needs the CurseForge required-file resolution capability; upgrade the worker first');
            }
            $context['recipe'] = $release->recipe();
            $context['recipe']['limits'] = ['max_archive_bytes' => (int) config('arcturus-modpack-manager.max_archive_bytes', 8589934592)];
            if ($release->canInspectArchive()) {
                $artifact = $release->gtnhArtifact;
                $pin = $trust->evidence($artifact);
                $context['recipe']['gtnh_archive'] = $artifact->identity();
                $this->archiveProgress($operation, $artifact);
                if (($pin['level'] ?? null) === 'rejected') {
                    throw new PreparationException(['code' => 'GTNH_ARCHIVE_REJECTED']);
                }
                if ($trust->usable($pin, $server)) {
                    $context['recipe'] = $this->pinnedRecipe($context['recipe'], $pin);
                } elseif ($pin !== null) {
                    $this->tracker->transition($operation, 'archive_review_required');
                    return true;
                } else {
                    if (! ($worker->request('capabilities')['gtnh_archive_quarantine'] ?? false)) {
                        throw new PreparationException(['code' => 'GTNH_QUARANTINE_UNAVAILABLE']);
                    }
                    $context['recipe']['gtnh_quarantine'] = true;
                    $context['recipe']['downloads'] = [];
                }
            }
            $context['release_name'] = $release->name;
            $operation->update(['context' => $context]);
            // Save intent before sending: a crash after submission resumes using the SAME operation ID.
            $this->tracker->transition($operation, 'downloading');
        }
        $context = $operation->context;
        $status = $worker->request('prepare', ['operation_id' => $operation->node_operation_id, 'recipe' => $context['recipe']]);
        if (($status['operation_id'] ?? null) !== $operation->node_operation_id) {
            throw new RuntimeException('Node returned a mismatched operation ID');
        }
        if (($context['recipe']['gtnh_quarantine'] ?? false) && ($status['state'] ?? null) === 'archive_measured') {
            $artifact = GtnhArtifact::fromArray($context['recipe']['gtnh_archive']);
            $pin = $trust->measure($artifact, $status['measurement'] ?? [], $server, $operation);
            $this->archiveProgress($operation, $artifact);
            if (($pin['level'] ?? null) === 'rejected') {
                throw new PreparationException(['code' => 'GTNH_ARCHIVE_REJECTED']);
            }
            if (! $trust->usable($pin, $server->refresh())) {
                $this->tracker->transition($operation, 'archive_review_required');
                return true;
            }
            // A second, pinned worker operation has a distinct immutable recipe and stage.
            $operation->update(['node_operation_id' => bin2hex(random_bytes(16)), 'heartbeat_at' => now(),
                'context' => [...$context, 'recipe' => $this->pinnedRecipe($context['recipe'], $pin)]]);
            return false;
        }
        if (($context['recipe']['gtnh_quarantine'] ?? false) && in_array($status['state'] ?? null, ['completed', 'extracting', 'hashing', 'awaiting_resolution'], true)) {
            throw new RuntimeException('A GTNH quarantine operation cannot expose a preparation stage.');
        }
        if (($status['state'] ?? null) === 'awaiting_resolution') {
            $provider = $this->providers->get($server->provider);
            if (! $provider instanceof RequiredFileResolver) {
                throw new RuntimeException('This provider cannot resolve the archived required-file manifest');
            }
            $resolution = $context['required_files'] ?? $worker->request('requirements', ['operation_id' => $operation->node_operation_id]);
            if (($resolution['sha256'] ?? null) !== ($status['requirements_sha256'] ?? null)) {
                throw new RuntimeException('CurseForge resolution checkpoint refers to a different archive manifest');
            }
            $batch = $provider->resolveBatch($resolution['manifest'], $resolution['files'] ?? []);
            $resolution['files'] = $batch['files'];
            $context['required_files'] = $resolution;
            $operation->update(['context' => $context, 'heartbeat_at' => now()]);
            if ($operation->phase !== 'preparing') {
                $this->tracker->transition($operation, 'preparing');
            }
            $operation->update(['context' => [...$context, 'progress' => ['state' => 'resolving required files',
                'files_resolved' => count($batch['files']), 'required_files' => $batch['total']]]]);
            PreparationProgress::phase($operation, 'awaiting_resolution', 'resolving_required_files', count($batch['files']), $batch['total']);
            if (! $batch['complete']) {
                return false;
            }
            $status = $worker->request('resolve', ['operation_id' => $operation->node_operation_id,
                'requirements_sha256' => $resolution['sha256'], 'files' => $batch['files']]);
        }
        $state = $status['state'] ?? null;
        if (($status['operation_id'] ?? null) !== $operation->node_operation_id || ! in_array($state, ['queued', 'downloading', 'verifying', 'extracting', 'hashing', 'completed', 'failed', 'cancelled', 'interrupted'], true)) {
            throw new RuntimeException('Node returned an unknown or mismatched preparation state');
        }
        if (isset($status['progress'])) { PreparationProgress::record($operation, $status); }
        if ($state === 'completed' && ! preg_match('/^[a-f0-9]{64}$/D', $status['manifest_sha256'] ?? '')) {
            throw new RuntimeException('Node completion has no verified manifest digest');
        }
        if (in_array($state, ['cancelled', 'interrupted'], true)) {
            $detail = PreparationDiagnostic::normalize($status['diagnostic'] ?? ['code' => strtoupper($state), 'phase' => $operation->progress['current']['phase'] ?? 'queued']);
            $operation->update(['error' => PreparationDiagnostic::message($detail), 'context' => [...$operation->context, 'diagnostic' => $detail]]);
            $this->tracker->transition($operation, $state);

            return true;
        }
        if ($state === 'failed') {
            $diagnostic = $status['diagnostic'] ?? ['code' => 'PREPARATION_FAILED', 'phase' => $operation->progress['current']['phase'] ?? 'queued'];
            if (isset($context['recipe']['gtnh_archive']) && ($diagnostic['code'] ?? null) === 'CHECKSUM_MISMATCH') {
                $diagnostic['code'] = 'GTNH_ARCHIVE_CHANGED';
            }
            throw new PreparationException($diagnostic);
        }
        if ($state !== 'completed') {
            $phase = match ($state) {
                'extracting' => 'preparing', 'hashing' => 'hashing', 'verifying' => 'verifying', default => 'downloading'
            };
            if ($operation->phase !== $phase) {
                $this->tracker->transition($operation, $phase);
            }
            $operation->update(['heartbeat_at' => now()]);
            if (! isset($status['progress'])) {
                PreparationProgress::phase($operation, in_array($state, PreparationDiagnostic::PHASES, true) ? $state : 'queued');
            }
            if (Operation::whereKey($operation->id)->value('cancel_requested_at')) {
                $worker->request('cancel', ['operation_id' => $operation->node_operation_id]);
            }

            return false;
        }
        if (Operation::whereKey($operation->id)->value('cancel_requested_at')) {
            throw new PreparationException(['code' => 'CANCELLED', 'phase' => $operation->progress['current']['phase'] ?? 'queued']);
        }
        $archiveEvidence = [];
        if (isset($context['recipe']['gtnh_archive'])) {
            $artifact = GtnhArtifact::fromArray($context['recipe']['gtnh_archive']);
            $pin = $trust->evidence($artifact);
            if (! $trust->usable($pin, $server->refresh()) || $this->pinnedRecipe($context['recipe'], $pin)['downloads'] !== $context['recipe']['downloads']) {
                throw new RuntimeException('GTNH archive trust changed before preparation completed.');
            }
            $archiveEvidence = ['archive_trust' => [...$artifact->identity(), ...$pin]];
        }
        $manifest = $worker->request('manifest', ['operation_id' => $operation->node_operation_id]);
        (new BaselineGuard)->require($manifest, $server->provider, $server->source, $context['version']);
        if ($archiveEvidence) {
            $reported = $manifest['artifacts'][0] ?? [];
            $pin = $archiveEvidence['archive_trust'];
            if (count($manifest['artifacts']) !== 1 || ($reported['sha256'] ?? null) !== $pin['sha256'] ||
                ($reported['hashes']['sha256'] ?? null) !== $pin['sha256'] || ($reported['bytes'] ?? null) !== $pin['size']) {
                throw new PreparationException(['code' => 'GTNH_ARCHIVE_CHANGED', 'phase' => 'verifying']);
            }
        }
        $preparedNodeId = $operation->node_operation_id;
        $preparedDigest = $status['manifest_sha256'];
        if ($server->transport === 'native' && $operation->kind !== 'baseline') {
            $pulse = function () use ($operation): void {
                if ($operation->fresh()->cancel_requested_at) { throw new PreparationException(['code' => 'CANCELLED', 'phase' => 'hashing']); }
                $operation->update(['heartbeat_at' => now()]);
            };
            $operation->update(['progress' => [...($operation->progress ?? []), 'runtime_preparation' => ['state' => 'running', 'message' => 'Checking and preparing the exact loader runtime in private Panel storage']]]);
            try {
                $built = app(\Arcturus\ModpackManager\Runtime\RuntimeBuild::class)->prepare($server, $manifest, $preparedNodeId, $preparedDigest, $pulse);
                $manifest = $built['manifest']; $preparedNodeId = $built['node_id']; $preparedDigest = $built['manifest_sha256'];
                $operation->update(['progress' => [...$operation->progress, 'runtime_preparation' => ['state' => 'completed', 'message' => isset($manifest['runtime_build']) ? 'Official installer runtime prepared and verified in an isolated workspace' : 'No installer preparation required; deployment qualification still applies']]]);
            } catch (PreparationException $error) { throw $error; }
            catch (\Throwable $error) {
                report($error);
                $message = get_class($error) === RuntimeException::class ? $error->getMessage() : 'Installer runtime preparation could not be verified; inspect the private Panel log.';
                $manifest['runtime']['preparation_blocker'] = $message;
                $operation->update(['progress' => [...$operation->progress, 'runtime_preparation' => ['state' => 'blocked', 'message' => $message]]]);
            }
        }
        $path = $this->store->put($manifest);
        $provenance = ['operation_id' => $operation->id, 'node_operation_id' => $preparedNodeId, 'transport' => $server->transport ?? 'wings',
            'provider' => $server->provider, 'project' => $server->source, 'version' => $context['version'],
            'manifest_sha256' => $preparedDigest, 'verification' => $archiveEvidence ? 'recorded-archive-pin' : 'upstream-checksums', ...$archiveEvidence];
        $this->tracker->transition($operation, 'comparing');
        PreparationProgress::phase($operation, 'comparing', 'inventory_enumeration');
        if ($operation->kind === 'baseline') {
            $server->update(['base_manifest_path' => $path, 'baseline_status' => 'verified',
                'baseline_provenance' => $provenance, 'adoption_report' => null, 'comparison_status' => 'incomplete', 'comparison_diagnostic' => null, 'approved_plan_sha256' => null]);
            app(AdoptionService::class)->compare($server, $operation);
        } else {
            $server->update(['target_manifest_path' => $path, 'prepared_root' => (match ($server->transport) { 'native' => 'native:', 'local' => 'local:', default => 'node:' }).$preparedNodeId,
                'target_metadata' => array_replace($server->target_metadata ?? $server->release_metadata ?? [], ['prepared' => $provenance, 'runtime' => $manifest['runtime']]),
                'preview_summary' => null, 'approved_plan_sha256' => null]);
            app(PreviewService::class)->generate($server, $operation);
        }
        if (Operation::whereKey($operation->id)->value('cancel_requested_at')) {
            throw new PreparationException(['code' => 'CANCELLED', 'phase' => 'comparing']);
        }
        $this->tracker->transition($operation, 'waiting_for_approval');
        $this->tracker->transition($operation, 'completed');

        return true;
    }

    private function archiveProgress(Operation $operation, GtnhArtifact $artifact): void
    {
        $operation->update(['progress' => [...($operation->progress ?? []), 'gtnh_archive' => $artifact->identity()]]);
    }

    private function pinnedRecipe(array $recipe, array $pin): array
    {
        unset($recipe['gtnh_quarantine']);
        $recipe['downloads'] = [['url' => $recipe['gtnh_archive']['url'], 'archive' => true,
            'hashes' => ['sha256' => $pin['sha256']], 'size' => $pin['size']]];
        return $recipe;
    }
}
