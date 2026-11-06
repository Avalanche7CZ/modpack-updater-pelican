<?php

declare(strict_types=1);

namespace Arcturus\ModpackManager\Services;

use App\Models\Server;
use Arcturus\ModpackManager\Models\ManagedServer;
use Arcturus\ModpackManager\Remote\ServerFilesFactory;
use Arcturus\ModpackManager\Security\Access;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/** Adoption has no power, reinstall, file-write, or startup-service dependency. */
final class AdoptionService
{
    public function __construct(private readonly ServerFilesFactory $files,
        private readonly RuntimeInspector $runtime, private readonly ReleaseStore $store,
        private readonly UpdatePlanner $planner) {}

    public function inspect(Server $server): array
    {
        $evidence = ['paths' => [], 'texts' => [], 'warnings' => [], 'complete' => false];
        $diagnostic = null;
        try {
            // A failed root listing must not erase the startup command, egg
            // and container image already known to Pelican. These are hints,
            // not proof of the running Java/loader.
            $files = $this->files->wings($server);
            $evidence = (new RuntimeEvidenceCollector)->collect($files);
        } catch (Throwable $error) {
            report($error);
            $status = $error instanceof RequestException ? $error->response->status() : null;
            $diagnostic = [
                'stage' => 'wings_root_listing',
                'code' => $status !== null ? 'HTTP_'.$status : (
                    $error instanceof ConnectionException ? 'CONNECTION' : 'METADATA_ERROR'
                ),
            ];
            $evidence['warnings'][] = $status !== null
                ? 'Wings file listing failed (HTTP '.$status.'). Check node connectivity and permissions.'
                : 'Could not list the server files through Wings. Check the Pelican Laravel log.';
        }
        $report = $this->runtime->inspect($evidence['paths'], $evidence['texts'], (string) $server->startup, (string) $server->image, $server->egg?->name);
        $report['warnings'] = array_values(array_unique(array_merge(
            $report['warnings'] ?? [], $evidence['warnings'] ?? []
        )));
        $report['inspection_complete'] = (bool) ($evidence['complete'] ?? false);
        $report['diagnostic'] = $diagnostic;
        $report['evidence_source'] = $diagnostic === null ? 'wings_files_and_panel_settings' : 'panel_settings_only';
        try {
            // World-root parsing must not discard independently collected
            // loader/Minecraft/Java evidence. An ambiguous level-name is a
            // deployment safety blocker, not a failed Wings file request.
            $report['protected_roots'] = SafetyPaths::roots($evidence['texts']['server.properties'] ?? '');
            // A missing server.properties provides no independent verification,
            // but is not evidence of an unsafe escaped level-name. Reserve
            // false for known ambiguity; null means not observed.
            $report['world_path_verified'] = isset($evidence['texts']['server.properties']) ? true : null;
        } catch (\InvalidArgumentException $error) {
            $report['protected_roots'] = SafetyPaths::roots();
            $report['world_path_verified'] = false;
            $report['inspection_complete'] = false;
            $report['warnings'][] = 'Custom world path could not be verified from server.properties. Resolve the level-name before enabling updates.';
            $report['world_path_diagnostic'] = 'manual_verification_required';
        }
        try {
            // Status is supplementary information. Missing power/status access
            // must not invalidate the file inspection or return a Livewire 500.
            $report['state'] = app(PelicanPowerController::class)->state($server);
        } catch (\Throwable $error) {
            $report['state'] = 'unavailable';
            $report['inspection_complete'] = false;
            $report['warnings'][] = 'Wings container state unavailable. Verify node connectivity and permissions.';
            report($error);
        }
        $report['warnings'] = array_values(array_unique($report['warnings']));
        $report['observed_at'] = now()->toIso8601String();

        return $report;
    }

    public function compare(ManagedServer $server, ?\Arcturus\ModpackManager\Models\Operation $operation = null): array
    {
        $revision = $server->policy_revision;
        $baseline = $server->base_manifest_path;
        DB::transaction(function () use ($server, $operation, $revision, $baseline): void {
            $fresh = ManagedServer::lockForUpdate()->findOrFail($server->id);
            if (($fresh->active_operation_id && $fresh->active_operation_id !== $operation?->id) ||
                $fresh->policy_revision !== $revision || $fresh->base_manifest_path !== $baseline) {
                throw new PreparationException(['code' => 'COMPARISON_STALE', 'phase' => 'comparing']);
            }
            $fresh->update(['comparison_status' => 'running', 'comparison_diagnostic' => null,
                'adoption_report' => null, 'file_inventory' => null, 'approved_plan_sha256' => null, 'approved_at' => null, 'approved_by' => null]);
        });
        try {
            return $this->compareInstallation($server, $operation);
        } catch (Throwable $error) {
            $detail = $error instanceof PreparationException ? $error->diagnostic : PreparationDiagnostic::normalize([
                'code' => $error instanceof \Arcturus\ModpackManager\Remote\CapabilityUnavailable ? 'WORKER_CONFIGURATION' : (($error instanceof \InvalidArgumentException || $error instanceof \TypeError || $error instanceof \JsonException) ? 'METADATA_INVALID' : 'COMPARISON_FAILED'),
                'phase' => 'comparing',
            ]);
            // A stale request cannot overwrite newer evidence or revive its approval.
            ManagedServer::whereKey($server->id)->where('policy_revision', $revision)->where('base_manifest_path', $baseline)
                ->where('comparison_status', 'running')->update(['comparison_status' => 'failed', 'comparison_diagnostic' => json_encode($detail, JSON_THROW_ON_ERROR)]);
            $server->events()->create(['kind' => 'comparison', 'status' => 'failed',
                'message' => PreparationDiagnostic::message($detail), 'details' => ['diagnostic' => $detail, 'operation_id' => $operation?->id]]);
            throw new PreparationException($detail);
        }
    }

    private function compareInstallation(ManagedServer $server, ?\Arcturus\ModpackManager\Models\Operation $operation): array
    {
        $base = $this->store->load($server->base_manifest_path);
        (new BaselineGuard)->require($base, $server->provider, $server->source, $server->installed_version);
        $inventory = $this->files->managed($server, $operation)->inventory();
        if ($operation) { PreparationProgress::phase($operation, 'comparing', 'planning'); }
        $observed = $this->inspect($server->pelicanServer);
        if (($observed['world_path_verified'] ?? null) === false) {
            throw new PreparationException(['code' => 'WORLD_PATH_UNVERIFIED', 'phase' => 'comparing']);
        }
        // Display inferred upstream fields separately; do not pretend they prove the running loader.
        $observed['upstream_runtime'] = $base['runtime'];
        $observed['observed_at'] = now()->toIso8601String();
        $rules = $server->file_rules ?? [];
        $safetyRoots = array_merge((new DirectoryPolicy)->protectedRoots($server, $base), $observed['protected_roots'] ?? [], $inventory['excluded_roots'] ?? []);
        $identities = (new ModIdentityMappings)->apply(
            ['base' => $base['identities'] ?? [], 'local' => $inventory['identities'] ?? [], 'target' => $base['identities'] ?? []],
            $server->mod_identity_mappings ?? [], ['base' => $base['files'], 'local' => $inventory['files'], 'target' => $base['files']]);
        $plan = $this->planner->plan($base['files'], $inventory['files'], $base['files'], $rules, $identities,
            $server->disabled_mods ?? [], $safetyRoots, $inventory['metadata'] ?? []);
        if ($operation) { PreparationProgress::phase($operation, 'comparing', 'planning', count($plan['changes']), count($plan['changes'])); }
        $report = ['policy_revision' => $server->policy_revision, 'verified_baseline' => true, 'version' => $base['version'], 'counts' => $plan['counts'],
            'changes' => $plan['changes'],
            'scope' => $inventory['scope'], 'excluded_roots' => $inventory['excluded_roots'],
            'skipped_symlinks' => $inventory['skipped_symlinks'] ?? [],
            'observed_at' => now()->toIso8601String(), 'notice' => $inventory['notice']];
        $saved = DB::transaction(function () use ($server, $report, $observed, $inventory, $operation): ManagedServer {
            $fresh = ManagedServer::lockForUpdate()->findOrFail($server->id);
            if ($fresh->policy_revision !== $server->policy_revision || $fresh->base_manifest_path !== $server->base_manifest_path) {
                throw new PreparationException(['code' => 'COMPARISON_STALE', 'phase' => 'comparing']);
            }
            if ($operation && \Arcturus\ModpackManager\Models\Operation::whereKey($operation->id)->value('cancel_requested_at')) {
                throw new PreparationException(['code' => 'CANCELLED', 'phase' => 'comparing']);
            }
            $fresh->update(['comparison_status' => 'complete', 'comparison_diagnostic' => null, 'adoption_report' => $report, 'runtime_report' => $observed, 'file_inventory' => [...$inventory, 'observed_at' => now()->toIso8601String()]]);

            return $fresh;
        });
        $server->setRawAttributes($saved->getAttributes(), true);

        return $report;
    }

    /** Record a manual update without reusing the old upstream baseline or changing live files. */
    public function reassociate(ManagedServer $server, string $version): void
    {
        Access::requireAdmin();
        if (trim($version) === '' || strlen($version) > 120 || preg_match('/[\x00-\x1f\x7f]/', $version)) {
            throw new RuntimeException('Enter a valid exact upstream release ID');
        }
        DB::transaction(function () use ($server, $version): void {
            $fresh = ManagedServer::lockForUpdate()->findOrFail($server->id);
            if ($fresh->active_operation_id) {
                throw new RuntimeException('Resolve the active operation before changing the installed release association');
            }
            if ($fresh->installed_version === $version) {
                throw new RuntimeException('The installed release association already matches');
            }
            $old = $fresh->installed_version;
            $runtime = $fresh->runtime_report ?? [];
            unset($runtime['upstream_runtime']);
            $fresh->update(['installed_version' => $version, 'baseline_status' => 'unverified',
                'comparison_status' => 'incomplete', 'comparison_diagnostic' => null, 'base_manifest_path' => null, 'baseline_provenance' => null, 'adoption_report' => null,
                'target_manifest_path' => null, 'target_metadata' => null, 'selected_version' => null,
                'prepared_root' => null, 'artifact_sha256' => null, 'preview_summary' => null,
                'approved_plan_sha256' => null, 'approved_by' => null, 'approved_at' => null,
                'adopted_at' => null, 'enabled' => false, 'deploy_enabled' => false, 'runtime_report' => $runtime]);
            $fresh->events()->create(['kind' => 'adoption', 'status' => 'warning', 'from_version' => $old,
                'to_version' => $version, 'message' => 'Manual release association recorded; upstream verification required. No live files changed.',
                'details' => ['actor_id' => auth()->id()]]);
        });
    }

    public function finish(ManagedServer $server, bool $allowUnverified = false): void
    {
        if (! $allowUnverified && (new DirectoryPolicy)->pending($server)) {
            throw new RuntimeException('Review the discovered upstream directories before finishing adoption');
        }
        // Approve the current recorded comparison; never start a large inventory in this web action.
        // Baseline and policy edits invalidate this evidence through the model revision guard.
        DB::transaction(function () use ($server, $allowUnverified): void {
            $fresh = ManagedServer::lockForUpdate()->findOrFail($server->id);
            if ($fresh->active_operation_id) {
                throw new RuntimeException('Wait for baseline preparation to finish');
            }
            if (! $allowUnverified && ($fresh->baseline_status !== 'verified' || $fresh->comparison_status !== 'complete' ||
                ($fresh->adoption_report['policy_revision'] ?? null) !== $fresh->policy_revision ||
                ($fresh->adoption_report['version'] ?? null) !== $fresh->installed_version || $fresh->base_manifest_path !== $server->base_manifest_path)) {
                throw new PreparationException(['code' => 'COMPARISON_STALE', 'phase' => 'comparing']);
            }
            $fresh->update(['adopted_at' => now(), 'enabled' => true,
                'deploy_enabled' => false, 'policy' => $fresh->policy ?? config('arcturus-modpack-manager.default_policy', 'approve'), 'baseline_status' => $allowUnverified ? 'unverified' : 'verified']);
            $fresh->events()->create(['kind' => 'adoption', 'status' => 'success',
                'message' => $allowUnverified ? 'Adopted for monitoring only; upstream baseline unverified' : 'Existing installation adopted after upstream comparison; no server files changed']);
        });
    }
}
