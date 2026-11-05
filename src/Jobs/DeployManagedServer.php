<?php
declare(strict_types=1);

namespace Arcturus\ModpackManager\Jobs;

use App\Models\Backup;
use App\Models\Server;
use Arcturus\ModpackManager\Models\ManagedServer;
use Arcturus\ModpackManager\Services\DirectoryInventory;
use Arcturus\ModpackManager\Services\DiscordNotifier;
use Arcturus\ModpackManager\Services\LocalDeployment;
use Arcturus\ModpackManager\Services\ManifestJson;
use Arcturus\ModpackManager\Services\PelicanBackupManager;
use Arcturus\ModpackManager\Services\PelicanPowerController;
use Arcturus\ModpackManager\Services\PreviewService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

/**
 * Production writes are gated on release verification and an OFFLINE backup.
 * Default backup backend: Pelican's own Wings/S3 backup infrastructure.
 */
final class DeployManagedServer implements ShouldQueue
{
    use Queueable;

    // Backup + staging verification + boot, or restore + second boot, can be slow.
    public int $timeout = 10800;
    public int $tries = 1;

    public function __construct(
        public readonly int $managedServerId,
        public readonly bool $automatic = false
    ) {}

    public function handle(
        PreviewService $previewer,
        ManifestJson $manifests,
        LocalDeployment $deployer,
        PelicanPowerController $power,
        PelicanBackupManager $backups,
        DirectoryInventory $inventory,
        DiscordNotifier $discord
    ): void {
        if (!config('modpack-manager.enable_deploy', false)) {
            throw new RuntimeException('Deploy feature not enabled by operator');
        }
        $lock = Cache::lock('modpack-manager-deploy-' . $this->managedServerId, 14400);
        if (!$lock->get()) {
            throw new RuntimeException('Another deploy already holds this server lock');
        }

        try {
            $managed = ManagedServer::findOrFail($this->managedServerId);
            if (!$managed->enabled || !$managed->deploy_enabled) {
                throw new RuntimeException('Server deployment disabled');
            }
            if ($this->automatic && (
                $managed->policy !== 'auto' ||
                !config('modpack-manager.automatic_deploy', false) ||
                ($managed->provider !== 'ftb' && !$managed->available_asset_sha256)
            )) {
                throw new RuntimeException('Automatic deployment policy or verified digest not satisfied');
            }
            $backupMode = $managed->backup_mode ?? 'pelican';
            if (!in_array($backupMode, ['pelican', 'filesystem'], true)) {
                throw new RuntimeException('Unknown backup backend; aborting');
            }
            if (!$managed->local_root || !$managed->prepared_root ||
                !$managed->base_manifest_path || !$managed->target_manifest_path ||
                ($backupMode === 'filesystem' && !$managed->backup_root)) {
                throw new RuntimeException('Missing live path, release manifests, staging, or snapshot directory');
            }

            $pelican = Server::query()->where('uuid', $managed->pelican_server_uuid)->firstOrFail();
            $original = $power->state($pelican);
            if (!in_array($original, ['running', 'exited'], true)) {
                throw new RuntimeException('Unsafe Pelican server state: ' . $original);
            }
            $preview = $previewer->generate($managed);
            if ($preview['blocked'] > 0) {
                throw new RuntimeException('Cannot deploy with unresolved dry-run conflicts');
            }
            if ($preview['to'] !== $managed->available_version ||
                $preview['from'] !== $managed->installed_version) {
                throw new RuntimeException('Release changed since approval');
            }

            $base = $manifests->load($managed->base_manifest_path);
            $target = $manifests->load($managed->target_manifest_path);
            $stage = realpath($managed->prepared_root);
            $stageParent = realpath((string) $managed->staging_parent);
            if (!$stage || !$stageParent ||
                !str_starts_with($stage, rtrim($stageParent, '/') . '/') ||
                !is_dir($stage) || is_link($managed->prepared_root)) {
                throw new RuntimeException('Prepared stage was moved or is outside configured staging parent');
            }

            $managed->events()->create([
                'kind' => 'deploy', 'status' => 'info',
                'from_version' => $base['version'], 'to_version' => $target['version'],
                'message' => 'Starting update using ' . $backupMode . ' backup backend',
            ]);

            // Manifest-managed hashes are a supplementary post-restore check.
            // Pelican's backup callback verifies the full archive/restore outcome.
            $trackedPaths = array_unique(array_merge(array_keys($base['files']), array_keys($target['files'])));
            $beforeHashes = $backupMode === 'pelican'
                ? $inventory->hashPaths($managed->local_root, $trackedPaths)
                : [];

            $pelicanBackup = null;
            $filesystemBackup = null;
            $backupInitiated = false;
            $backupCompleted = false;
            $deploymentStarted = false;
            $restored = false;
            try {
                $power->stop($pelican);

                if ($backupMode === 'pelican') {
                    // Pelican uses configured Wings/S3 hosts, quota and retention.
                    // No override: reaching backup quota stops the update instead
                    // of deleting someone's existing backup.
                    $name = 'Pre-update ' . mb_substr($managed->label, 0, 70) .
                        ' ' . $base['version'] . ' -> ' . $target['version'];
                    $pelicanBackup = $backups->initiate($pelican, $name);
                    $backupInitiated = true;
                    $managed->forceFill(['last_backup_uuid' => $pelicanBackup->uuid])->save();
                    $managed->events()->create([
                        'kind' => 'backup', 'status' => 'info',
                        'from_version' => $base['version'], 'to_version' => $target['version'],
                        'message' => 'Pelican locked backup started',
                        'details' => ['backup_uuid' => $pelicanBackup->uuid, 'backend' => 'pelican'],
                    ]);
                    $backups->awaitSuccessful($pelicanBackup);
                    $backupCompleted = true;
                    $managed->events()->create([
                        'kind' => 'backup', 'status' => 'success',
                        'message' => 'Pelican confirmed completed locked backup',
                        'details' => [
                            'backup_uuid' => $pelicanBackup->uuid,
                            'bytes' => (int) $pelicanBackup->bytes,
                            'host' => $pelicanBackup->backupHost?->schema,
                        ],
                    ]);
                }

                $deploymentStarted = true;
                $result = $deployer->apply(
                    $managed->local_root,
                    $stage,
                    $backupMode === 'filesystem' ? $managed->backup_root : '',
                    $base['files'], $target['files'], $managed->file_rules ?? [],
                    true,
                    $pelicanBackup?->uuid
                );
                $filesystemBackup = $result['backup'];
                $power->startAndVerify($pelican, $managed->local_root);
                if ($original === 'exited') {
                    $power->stop($pelican);
                }

                $managed->forceFill([
                    'installed_version' => $target['version'],
                    'base_manifest_path' => $managed->target_manifest_path,
                    'target_manifest_path' => null,
                    'prepared_root' => null,
                    'preview_summary' => null,
                    'last_preview_at' => null,
                    'last_success_at' => now(),
                    'last_error' => null,
                ])->save();
                $managed->events()->create([
                    'kind' => 'deploy', 'status' => 'success',
                    'from_version' => $base['version'], 'to_version' => $target['version'],
                    'message' => 'Update installed; Minecraft startup marker confirmed',
                    'details' => [
                        'backup_backend' => $backupMode,
                        'backup_uuid' => $pelicanBackup?->uuid,
                        'filesystem_backup' => $filesystemBackup,
                        'modified' => $result['modified'],
                    ],
                ]);
                try {
                    $discord->announce($managed, 'updated',
                        "Server updated from **{$base['version']}** to **{$target['version']}**. " .
                        "Startup verified; backup: " .
                        ($pelicanBackup?->uuid ?? $filesystemBackup ?? 'unknown'));
                } catch (Throwable $notificationError) {
                    report($notificationError);
                }
            } catch (Throwable $error) {
                $recovery = 'not attempted';
                try {
                    if ($deploymentStarted) {
                        $power->stop($pelican);
                        if ($backupMode === 'pelican' && $pelicanBackup && $backupCompleted) {
                            $backups->restoreAndWait($pelican, $pelicanBackup);
                            $actual = $inventory->hashPaths($managed->local_root, $trackedPaths);
                            if ($actual !== $beforeHashes) {
                                throw new RuntimeException('Wings reported restore success, but managed files differ');
                            }
                            $restored = true;
                            $recovery = 'Pelican full restore confirmed (' . $pelicanBackup->uuid . ')';
                        } elseif ($backupMode === 'filesystem' && $filesystemBackup !== null) {
                            $failedInstallation = $deployer->restore(
                                $managed->local_root, $filesystemBackup, true
                            );
                            $restored = true;
                            $recovery = 'local snapshot restored; failed install at ' . $failedInstallation;
                        } else {
                            // A filesystem copy error is normally file-rolled-back
                            // by LocalDeployment, but a failed rollback is possible.
                            // Fail closed: do not restart without a verifiable snapshot.
                            $recovery = 'No confirmed snapshot for automatic recovery; server left stopped';
                        }
                    } elseif ($backupInitiated && !$backupCompleted) {
                        $recovery = 'Pelican backup incomplete; server left stopped';
                    } else {
                        $recovery = 'No game files modified';
                    }

                    if ($original === 'running' &&
                        (!$deploymentStarted && !$backupInitiated || $restored)) {
                        $power->startAndVerify($pelican, $managed->local_root);
                    }
                } catch (Throwable $rollbackError) {
                    $recovery = 'RECOVERY FAILED: ' . $rollbackError->getMessage();
                    report($rollbackError);
                }
                $message = mb_substr($error->getMessage() . ' | ' . $recovery, 0, 1800);
                $managed->forceFill(['last_error' => $message])->save();
                $managed->events()->create([
                    'kind' => 'deploy', 'status' => 'failed',
                    'from_version' => $base['version'], 'to_version' => $target['version'],
                    'message' => $message,
                    'details' => [
                        'backup_backend' => $backupMode,
                        'backup_uuid' => $pelicanBackup?->uuid,
                        'filesystem_backup' => $filesystemBackup,
                        'recovery' => $recovery,
                    ],
                ]);
                try {
                    $discord->announce($managed, 'failed', 'Update failed: ' . $message);
                } catch (Throwable $notificationError) {
                    report($notificationError);
                }
                throw new RuntimeException('Update failed: ' . $message, previous: $error);
            }
        } finally {
            $lock->release();
        }
    }
}
