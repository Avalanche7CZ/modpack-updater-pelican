<?php
declare(strict_types=1);

namespace Arcturus\ModpackManager\Jobs;

use App\Models\Server;
use Arcturus\ModpackManager\Models\ManagedServer;
use Arcturus\ModpackManager\Services\DiscordNotifier;
use Arcturus\ModpackManager\Services\LocalDeployment;
use Arcturus\ModpackManager\Services\ManifestJson;
use Arcturus\ModpackManager\Services\PelicanPowerController;
use Arcturus\ModpackManager\Services\PreviewService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

/**
 * Dangerous operations are gated behind global + per-server opt-ins, a fresh
 * conflict-free manifest plan, explicit Pelican UUID binding, verified backups,
 * and confirmation of Wings' actual stopped state.
 */
final class DeployManagedServer implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;
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
        DiscordNotifier $discord
    ): void {
        if (!config('modpack-manager.enable_deploy', false)) {
            throw new RuntimeException('Deploy feature not enabled by operator');
        }
        $lock = Cache::lock('modpack-manager-deploy-' . $this->managedServerId, 7200);
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
            if (!$managed->local_root || !$managed->prepared_root ||
                !$managed->base_manifest_path || !$managed->target_manifest_path ||
                !$managed->backup_root) {
                throw new RuntimeException('Missing host path, manifest, stage or backup configuration');
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
                'message' => 'Starting managed deployment',
            ]);

            $backup = null;
            $startedForVerification = false;
            try {
                $power->stop($pelican);
                $result = $deployer->apply(
                    $managed->local_root, $stage, $managed->backup_root,
                    $base['files'], $target['files'], $managed->file_rules ?? [], true
                );
                $backup = $result['backup'];
                $power->startAndVerify($pelican, $managed->local_root);
                $startedForVerification = true;
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
                    'message' => 'Update installed, Minecraft ready marker confirmed',
                    'details' => ['backup' => $backup, 'modified' => $result['modified']],
                ]);
                try {
                    $discord->announce($managed, 'updated',
                        "Server updated from **{$base['version']}** to **{$target['version']}**. Ready check passed.");
                } catch (Throwable $notificationError) {
                    report($notificationError);
                }
            } catch (Throwable $error) {
                $recovery = 'not attempted';
                try {
                    // Deployer's own file-level rollback handles file-copy failures.
                    // Full restore is required if Minecraft fails to start afterward.
                    if ($backup) {
                        $power->stop($pelican);
                        $failedInstallation = $deployer->restore(
                            $managed->local_root, $backup, true
                        );
                        $recovery = 'full snapshot restored; failed install at ' . $failedInstallation;
                    } else {
                        $recovery = 'local deployment did not complete; file rollback handled by worker';
                    }
                    if ($original === 'running') {
                        $power->startAndVerify($pelican, $managed->local_root);
                    }
                } catch (Throwable $rollbackError) {
                    $recovery = 'ROLLBACK FAILED: ' . $rollbackError->getMessage();
                    report($rollbackError);
                }
                $message = mb_substr($error->getMessage() . ' | ' . $recovery, 0, 1800);
                $managed->forceFill(['last_error' => $message])->save();
                $managed->events()->create([
                    'kind' => 'deploy', 'status' => 'failed',
                    'from_version' => $base['version'], 'to_version' => $target['version'],
                    'message' => $message,
                    'details' => ['backup' => $backup, 'recovery' => $recovery],
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
