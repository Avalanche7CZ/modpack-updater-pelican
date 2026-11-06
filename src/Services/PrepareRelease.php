<?php

declare(strict_types=1);

namespace Arcturus\ModpackManager\Services;

use Arcturus\ModpackManager\Contracts\RequiredFileResolver;
use Arcturus\ModpackManager\Models\ManagedServer;
use Arcturus\ModpackManager\Models\Operation;
use Arcturus\ModpackManager\Modpacks\ProviderRegistry;
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
        if ($worker instanceof LocalWorker) {
            $worker->withHeartbeat(fn () => $operation->update(['heartbeat_at' => now()]));
        }
        if ($operation->phase === 'queued') {
            $release = $this->providers->get($server->provider)->release($server->source, $context['version'], $server->asset_pattern);
            if (! $release->canPrepare()) {
                throw new RuntimeException(implode('; ', $release->blockers) ?: 'Upstream download checksums or required files are missing');
            }
            if ($release->format === 'curseforge' && ! ($worker->request('capabilities')['curseforge_resolution'] ?? false)) {
                throw new RuntimeException('This node worker needs the CurseForge required-file resolution capability; upgrade the worker first');
            }
            $context['recipe'] = $release->recipe();
            $context['recipe']['limits'] = ['max_archive_bytes' => (int) config('arcturus-modpack-manager.max_archive_bytes', 8589934592)];
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
            if (! $batch['complete']) {
                return false;
            }
            $status = $worker->request('resolve', ['operation_id' => $operation->node_operation_id,
                'requirements_sha256' => $resolution['sha256'], 'files' => $batch['files']]);
        }
        $state = $status['state'] ?? null;
        if (($status['operation_id'] ?? null) !== $operation->node_operation_id || ! in_array($state, ['queued', 'downloading', 'extracting', 'hashing', 'completed', 'failed', 'cancelled', 'interrupted'], true)) {
            throw new RuntimeException('Node returned an unknown or mismatched preparation state');
        }
        if ($state === 'completed' && ! preg_match('/^[a-f0-9]{64}$/D', $status['manifest_sha256'] ?? '')) {
            throw new RuntimeException('Node completion has no verified manifest digest');
        }
        if (in_array($state, ['cancelled', 'interrupted'], true)) {
            $operation->update(['error' => $status['error'] ?? 'Node preparation '.$state]);
            $this->tracker->transition($operation, $state);

            return true;
        }
        if ($state === 'failed') {
            throw new RuntimeException('Node preparation '.$state.': '.($status['error'] ?? 'inspect node operation'));
        }
        if ($state !== 'completed') {
            $phase = match ($state) {
                'extracting' => 'preparing', 'hashing' => 'comparing', default => 'downloading'
            };
            if ($operation->phase !== $phase) {
                $this->tracker->transition($operation, $phase);
            }
            $operation->update(['heartbeat_at' => now(), 'context' => [...$context, 'progress' => array_intersect_key($status,
                array_flip(['bytes_downloaded', 'files_verified', 'current_file', 'total_files', 'state']))]]);

            return false;
        }
        $manifest = $worker->request('manifest', ['operation_id' => $operation->node_operation_id]);
        (new BaselineGuard)->require($manifest, $server->provider, $server->source, $context['version']);
        $path = $this->store->put($manifest);
        $provenance = ['operation_id' => $operation->id, 'node_operation_id' => $operation->node_operation_id, 'transport' => $server->transport ?? 'wings',
            'provider' => $server->provider, 'project' => $server->source, 'version' => $context['version'],
            'manifest_sha256' => $status['manifest_sha256'], 'verification' => 'upstream-checksums'];
        if ($operation->kind === 'baseline') {
            $server->update(['base_manifest_path' => $path, 'baseline_status' => 'verified',
                'baseline_provenance' => $provenance, 'approved_plan_sha256' => null]);
            app(AdoptionService::class)->compare($server);
        } else {
            $server->update(['target_manifest_path' => $path, 'prepared_root' => ($server->transport === 'local' ? 'local:' : 'node:').$operation->node_operation_id,
                'target_metadata' => array_replace($server->target_metadata ?? $server->release_metadata ?? [], ['prepared' => $provenance, 'runtime' => $manifest['runtime']]),
                'preview_summary' => null, 'approved_plan_sha256' => null]);
            app(PreviewService::class)->generate($server);
        }
        $this->tracker->transition($operation, 'waiting_for_approval');
        $this->tracker->transition($operation, 'completed');

        return true;
    }
}
