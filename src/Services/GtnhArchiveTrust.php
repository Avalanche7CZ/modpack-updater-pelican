<?php

declare(strict_types=1);

namespace Arcturus\ModpackManager\Services;

use App\Models\User;
use Arcturus\ModpackManager\Models\{ArchiveTrust, ManagedServer, Operation};
use Arcturus\ModpackManager\Modpacks\Providers\GtnhArtifact;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class GtnhArchiveTrust
{
    public function evidence(GtnhArtifact $artifact): ?array
    {
        return $artifact->reviewedPin() ?? ArchiveTrust::find($artifact->id())?->only(['sha256', 'size', 'level', 'provenance']);
    }

    public function usable(?array $pin, ManagedServer $server): bool
    {
        return in_array($pin['level'] ?? null, ['reviewed_pin', 'admin_reviewed'], true)
            || (($pin['level'] ?? null) === 'tofu' && $server->gtnh_trust_mode === 'tofu');
    }

    public function measure(GtnhArtifact $artifact, array $measurement, ManagedServer $server, Operation $operation): array
    {
        $sha = $measurement['sha256'] ?? null;
        $size = $measurement['size'] ?? null;
        if (! is_string($sha) || ! preg_match('/^[a-f0-9]{64}$/D', $sha) || ! is_int($size) || $size <= 0
            || $size > (int) config('arcturus-modpack-manager.max_archive_bytes', 8589934592)) {
            throw new RuntimeException('Invalid GTNH archive measurement.');
        }

        return DB::transaction(function () use ($artifact, $sha, $size, $server, $operation): array {
            $op = Operation::lockForUpdate()->findOrFail($operation->id, ['id', 'managed_server_id', 'kind', 'node_operation_id', 'cancel_requested_at']);
            $owner = ManagedServer::lockForUpdate()->findOrFail($server->id, ['id', 'provider', 'source', 'asset_pattern',
                'installed_version', 'selected_version', 'available_version', 'active_operation_id', 'gtnh_trust_mode']);
            $version = $op->kind === 'baseline' ? $owner->installed_version : $owner->targetVersion();
            if ($owner->active_operation_id !== $op->id || $op->managed_server_id !== $owner->id || $op->cancel_requested_at
                || $op->node_operation_id !== $operation->node_operation_id || ! in_array($op->kind, ['baseline', 'prepare'], true)
                || $owner->provider !== 'gtnh' || $owner->source !== 'gtnh' || $owner->asset_pattern !== $artifact->variant || $version !== $artifact->version) {
                throw new RuntimeException('GTNH preparation was superseded or cancelled before trust enrollment.');
            }
            if ($pin = $artifact->reviewedPin()) {
                $this->requireMatch($pin, $sha, $size);
                return $pin;
            }

            $now = now();
            $tofu = $owner->gtnh_trust_mode === 'tofu';
            // Unique artifact identity is the cross-server fence. A conflicting insert never updates existing bytes.
            ArchiveTrust::query()->toBase()->upsert([[
                'id' => $artifact->id(), ...$artifact->identity(), 'sha256' => $sha, 'size' => $size,
                'level' => $tofu ? 'tofu' : 'pending',
                'provenance' => $tofu ? 'official_https_tofu_measurement' : 'official_https_quarantine_measurement',
                'measurement_operation_id' => $op->id, 'worker_operation_id' => $op->node_operation_id,
                'verified_at' => $now, 'decided_at' => $tofu ? $now : null, 'created_at' => $now, 'updated_at' => $now,
            ]], ['id'], ['id']);
            $row = ArchiveTrust::lockForUpdate()->findOrFail($artifact->id());
            $pin = $row->only(['sha256', 'size', 'level', 'provenance']);
            $this->requireMatch($pin, $sha, $size);
            return $pin;
        }, 3);
    }

    private function requireMatch(array $pin, string $sha, int $size): void
    {
        if (! hash_equals($pin['sha256'], $sha) || $pin['size'] !== $size) {
            throw new PreparationException(['code' => 'GTNH_ARCHIVE_CHANGED', 'phase' => 'verifying']);
        }
    }

    public function decide(GtnhArtifact $artifact, string $sha, int $size, int $actor, bool $approve): void
    {
        if (! User::find($actor)?->isRootAdmin() || $artifact->reviewedPin() !== null) {
            throw new RuntimeException('Only a root administrator may decide a pending GTNH archive.');
        }
        DB::transaction(function () use ($artifact, $sha, $size, $actor, $approve): void {
            $row = ArchiveTrust::lockForUpdate()->findOrFail($artifact->id());
            if (($row->level !== 'pending' && ! ($row->level === 'tofu' && $approve)) || $row->sha256 !== $sha || $row->size !== $size) {
                throw new RuntimeException('The exact measurement is no longer pending. Reload its recorded decision.');
            }
            $row->update(['level' => $approve ? 'admin_reviewed' : 'rejected', 'actor_id' => $actor, 'decided_at' => now()]);
        }, 3);
    }
}
