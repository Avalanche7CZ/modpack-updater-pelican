<?php

declare(strict_types=1);

namespace Arcturus\ModpackManager\Services;

use Arcturus\ModpackManager\Models\ManagedServer;
use RuntimeException;

final class DeploymentGate
{
    public function require(ManagedServer $server, ?string $approvedDigest): void
    {
        if ($server->transport !== 'local') {
            throw new RuntimeException('Remote deployment is disabled: a qualified node transaction and exclusion-preserving restore are not available');
        }
        if (! $server->adopted_at || $server->baseline_status !== 'verified') {
            throw new RuntimeException('Verified adoption is required before deployment');
        }
        $preview = $server->preview_summary ?? [];
        if (! ($preview['applicable'] ?? false) || ! $approvedDigest ||
            ! hash_equals($server->approved_plan_sha256 ?? '', $approvedDigest) ||
            ! hash_equals($preview['plan_sha256'] ?? '', $approvedDigest) ||
            ! $server->approved_at || $server->approved_at->lt(now()->subMinutes(30))) {
            throw new RuntimeException('A current, compatible and explicitly approved exact plan is required');
        }
    }
}
