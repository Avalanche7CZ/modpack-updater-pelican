<?php

declare(strict_types=1);

namespace Arcturus\ModpackManager\Services;

use App\Models\Server;
use Arcturus\ModpackManager\Models\ManagedServer;
use Arcturus\ModpackManager\Remote\ServerFilesFactory;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Adoption has no power, reinstall, file-write, or startup-service dependency. */
final class AdoptionService
{
    public function __construct(private readonly ServerFilesFactory $files,
        private readonly RuntimeInspector $runtime, private readonly ReleaseStore $store,
        private readonly UpdatePlanner $planner) {}

    public function inspect(Server $server): array
    {
        $files = $this->files->wings($server);
        $root = $files->listing();
        $texts = [];
        $paths = array_column($root, 'name');
        foreach (['modrinth.index.json', 'manifest.json', 'modpack.json', 'server.properties',
            'fabric-server-launcher.properties', 'quilt-server-launcher.properties', 'run.sh', 'startserver.sh', 'start.sh'] as $path) {
            if (in_array($path, $paths, true)) {
                $texts[$path] = $files->read($path);
            }
        }
        $report = $this->runtime->inspect($paths, $texts, $server->startup, $server->image, $server->egg?->name);
        $report['state'] = app(PelicanPowerController::class)->state($server);
        $report['observed_at'] = now()->toIso8601String();

        return $report;
    }

    public function compare(ManagedServer $server): array
    {
        $base = $this->store->load($server->base_manifest_path);
        (new BaselineGuard)->require($base, $server->provider, $server->source, $server->installed_version);
        $inventory = $this->files->wings($server->pelicanServer)->inventory();
        $paths = array_keys($inventory['files']);
        $observed = $this->runtime->inspect($paths, [], $server->pelicanServer->startup,
            $server->pelicanServer->image, $server->pelicanServer->egg?->name);
        // Display inferred upstream fields separately; do not pretend they prove the running loader.
        $observed['state'] = app(PelicanPowerController::class)->state($server->pelicanServer);
        $observed['upstream_runtime'] = $base['runtime'];
        $observed['observed_at'] = now()->toIso8601String();
        $rules = $server->file_rules ?? [];
        foreach ($inventory['excluded_roots'] ?? [] as $root) {
            UpdatePlanner::assertPath($root);
            $rules[] = ['pattern' => $root.'/**', 'mode' => 'protect'];
        }
        $identities = (new ModIdentityMappings)->apply(
            ['base' => $base['identities'] ?? [], 'local' => $inventory['identities'] ?? [], 'target' => $base['identities'] ?? []],
            $server->mod_identity_mappings ?? [], ['base' => $base['files'], 'local' => $inventory['files'], 'target' => $base['files']]);
        $plan = $this->planner->plan($base['files'], $inventory['files'], $base['files'], $rules, $identities,
            $server->disabled_mods ?? []);
        $report = ['verified_baseline' => true, 'version' => $base['version'], 'counts' => $plan['counts'],
            'changes' => array_values(array_filter($plan['changes'], fn ($c) => $c['action'] !== 'unchanged')),
            'scope' => $inventory['scope'], 'excluded_roots' => $inventory['excluded_roots'],
            'observed_at' => now()->toIso8601String(), 'notice' => $inventory['notice']];
        $server->update(['adoption_report' => $report, 'runtime_report' => $observed]);

        return $report;
    }

    public function finish(ManagedServer $server, bool $allowUnverified = false): void
    {
        if (! $allowUnverified) {
            $this->compare($server);
        }
        DB::transaction(function () use ($server, $allowUnverified): void {
            $fresh = ManagedServer::lockForUpdate()->findOrFail($server->id);
            if ($fresh->active_operation_id) {
                throw new RuntimeException('Wait for baseline preparation to finish');
            }
            $fresh->update(['adopted_at' => now(), 'enabled' => true,
                'deploy_enabled' => false, 'policy' => $fresh->policy ?? config('arcturus-modpack-manager.default_policy', 'approve'), 'baseline_status' => $allowUnverified ? 'unverified' : 'verified']);
            $fresh->events()->create(['kind' => 'adoption', 'status' => 'success',
                'message' => $allowUnverified ? 'Adopted for monitoring only; upstream baseline unverified' : 'Existing installation adopted after upstream comparison; no server files changed']);
        });
    }
}
