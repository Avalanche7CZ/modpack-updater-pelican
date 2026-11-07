<?php

declare(strict_types=1);

namespace Arcturus\ModpackManager\Services;

use Arcturus\ModpackManager\Models\ManagedServer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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
        $lock = Cache::lock('arcturus-modpack-manager-check-'.$server->id, 180);
        if (! $lock->get()) {
            return ['state' => 'busy'];
        }
        try {
            $server->refresh();
            if ($server->active_operation_id) {
                return ['state' => 'busy'];
            }
            $wasAvailable = $server->available_version;
            try {
                $release = $this->discoverer->discover($server);
                $version = $release['version'];
                $new = $version !== $server->installed_version && $wasAvailable !== $version;
                $saved = DB::transaction(function () use ($server, $release, $version, $wasAvailable): bool {
                    $fresh = ManagedServer::lockForUpdate()->findOrFail($server->id);
                    if ($fresh->active_operation_id || $fresh->provider !== $server->provider || $fresh->source !== $server->source) {
                        return false;
                    }
                    $attributes = [
                        'available_version' => $version,
                        'available_asset_url' => $release['asset_url'] ?: null,
                        'available_asset_sha256' => $release['sha256'],
                        'available_release_url' => $release['release_url'],
                        'last_checked_at' => now(), 'last_check_attempt_at' => now(),
                        'release_metadata' => $release['metadata'], 'last_error' => null,
                    ];
                    if (! $fresh->selected_version && $wasAvailable !== $version) {
                        $attributes += ['target_manifest_path' => null, 'prepared_root' => null, 'target_metadata' => null,
                            'preview_summary' => null, 'approved_plan_sha256' => null, 'approved_at' => null];
                    }
                    $fresh->forceFill($attributes)->save();
                    $server->setRawAttributes($fresh->getAttributes(), true);

                    return true;
                });
                if (! $saved) {
                    return ['state' => 'busy'];
                }

                if ($new) {
                    $event = $server->events()->create([
                        'kind' => 'release', 'status' => 'success',
                        'from_version' => $server->installed_version,
                        'to_version' => $version,
                        'message' => 'New upstream release detected',
                        'details' => ['artifact_available' => $release['asset_url'] !== null, 'release_url' => $release['release_url'], 'discord_notification_pending' => true],
                    ]);
                    $this->notifier->forEvent($event);

                }

                return ['state' => $version === $server->installed_version ? 'current' : 'available',
                    'version' => $version];
            } catch (Throwable $error) {
                $oldError = $server->last_error;
                $msg = mb_substr($error->getMessage(), 0, 1500);
                $server->forceFill(['last_error' => $msg, 'last_check_attempt_at' => now()])->save();
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
