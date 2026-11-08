<?php

declare(strict_types=1);

namespace Arcturus\ModpackManager\Services;

use Arcturus\ModpackManager\Local\LocalWorker;
use Arcturus\ModpackManager\Models\ManagedServer;
use Arcturus\ModpackManager\Security\Access;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class FilesystemBackendSettings
{
    public function save(ManagedServer $server, string $transport): void
    {
        Access::requireAdmin();
        if (! in_array($transport, ['native', 'wings', 'local'], true)) {
            throw new RuntimeException('Select a supported filesystem backend');
        }
        $entry = [];
        if ($transport === 'local') {
            $candidate = clone $server;
            $candidate->local_root = null;
            $candidate->staging_parent = null;
            $worker = new LocalWorker($candidate);
            $entries = $worker->configuration();
            $worker->request('provision');
            $entry = $entries[$server->pelican_server_uuid];
        }
        DB::transaction(function () use ($server, $transport, $entry): void {
            $fresh = ManagedServer::lockForUpdate()->findOrFail($server->id);
            if ($fresh->active_operation_id) {
                throw new RuntimeException('Wait for the active operation before changing filesystem backend');
            }
            $paths = ['transport' => $transport, 'local_root' => $entry['root'] ?? null,
                'staging_parent' => $entry['staging'] ?? null];
            if ($fresh->transport === $transport && $fresh->local_root === $paths['local_root'] && $fresh->staging_parent === $paths['staging_parent']) {
                return;
            }
            if ($transport === 'native') {
                $fresh->update([...$paths, 'policy_revision' => $fresh->policy_revision + 1, 'target_manifest_path' => null, 'prepared_root' => null, 'target_metadata' => null,
                    'file_inventory' => null, 'preview_summary' => null, 'last_preview_at' => null,
                    'approved_plan_sha256' => null, 'approved_at' => null, 'approved_by' => null]);
                $fresh->events()->create(['kind' => 'filesystem', 'status' => 'success',
                    'message' => 'Native Pelican/Wings selected. Verified upstream baseline and protection policies retained. Prepare the target again; no host volume permissions are needed.']);
                return;
            }
            $fresh->update([...$paths, 'baseline_status' => 'unverified', 'base_manifest_path' => null,
                'baseline_provenance' => null, 'target_manifest_path' => null, 'prepared_root' => null,
                'target_metadata' => null, 'file_inventory' => null, 'adoption_report' => null,
                'preview_summary' => null, 'approved_plan_sha256' => null, 'approved_at' => null, 'approved_by' => null]);
            $fresh->events()->create(['kind' => 'filesystem', 'status' => 'success',
                'message' => 'Filesystem backend selected: '.$transport.'. Prepare a new verified baseline. No server files or policies changed.']);
        });
    }
}
