<?php
declare(strict_types=1);

namespace Arcturus\ModpackManager\Services;

use Arcturus\ModpackManager\Models\ManagedServer;
use Arcturus\ModpackManager\Jobs\PrepareUpdate;
use Illuminate\Support\Facades\Cache;
use Throwable;

final class ReleaseChecker
{
    public function __construct(
        private readonly ReleaseDiscoverer $discoverer,
        private readonly DiscordNotifier $notifier
    ) {}

    /** @return array{state:string,version?:string,error?:string} */
    public function check(ManagedServer $server): array
    {
        $lock = Cache::lock('arcturus-modpack-manager-check-' . $server->id, 90);
        if (!$lock->get()) {
            return ['state' => 'busy'];
        }
        try {
            $server->refresh();
            $wasAvailable = $server->available_version;
            try {
                $release = $this->discoverer->discover($server);
                $version = $release['version'];
                $new = $version !== $server->installed_version && $wasAvailable !== $version;
                $server->forceFill([
                    'available_version' => $version,
                    'available_asset_url' => $release['asset_url'] ?: null,
                    'available_asset_sha256' => $release['sha256'],
                    'available_release_url' => $release['release_url'],
                    'last_checked_at' => now(),
                    'last_error' => null,
                ])->save();

                if ($new) {
                    $server->events()->create([
                        'kind' => 'release', 'status' => 'success',
                        'from_version' => $server->installed_version,
                        'to_version' => $version,
                        'message' => 'New upstream release detected',
                        'details' => ['artifact_available' => $release['asset_url'] !== null],
                    ]);
                    try {
                        $this->notifier->announce($server, 'update_available',
                            "Version **{$version}** is available. Approval or a separate deployment worker is required.");
                    } catch (Throwable $error) {
                        report($error);
                    }
                    if ($server->policy === 'auto' && $server->deploy_enabled &&
                        config('arcturus-modpack-manager.automatic_deploy', false) &&
                        config('arcturus-modpack-manager.enable_deploy', false)) {
                        PrepareUpdate::dispatch($server->id);
                    }
                }
                return ['state' => $version === $server->installed_version ? 'current' : 'available',
                    'version' => $version];
            } catch (Throwable $error) {
                $oldError = $server->last_error;
                $msg = mb_substr($error->getMessage(), 0, 1500);
                $server->forceFill(['last_error' => $msg, 'last_checked_at' => now()])->save();
                if ($oldError !== $msg) {
                    $server->events()->create([
                        'kind' => 'check', 'status' => 'failed', 'message' => $msg,
                    ]);
                }
                return ['state' => 'failed', 'error' => $msg];
            }
        } finally {
            $lock->release();
        }
    }
}
