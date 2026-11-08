<?php

declare(strict_types=1);

namespace Arcturus\ModpackManager\Services;

use Arcturus\ModpackManager\Models\ManagedServer;
use RuntimeException;

final class DeploymentGate
{
    public function requireReady(ManagedServer $server): void
    {
        $preview = $server->preview_summary ?? [];
        if (($preview['comparison_schema'] ?? null) !== ManifestComparison::SCHEMA) { throw new RuntimeException('Compare files again: this plan predates manifest-scoped comparison. Previous approvals cannot be reused.'); }
        $native = $server->transport === 'native' && ($preview['qualification']['protocol'] ?? '') === \Arcturus\ModpackManager\Native\Qualification::PROTOCOL &&
            ($server->target_metadata['prepared']['transport'] ?? '') === 'native';
        $local = $server->transport === 'local' && ($preview['qualification']['protocol'] ?? '') === SameHostDeployment::PROTOCOL &&
            ($server->baseline_provenance['transport'] ?? '') === 'local' && ($server->target_metadata['prepared']['transport'] ?? '') === 'local';
        if (! $native && ! $local) { throw new RuntimeException('Prepare and qualify the target using Native Pelican/Wings or the explicit legacy local backend. Inspect comparison blockers.'); }
        if ($server->comparison_status !== 'complete' || ! $server->enabled || ! $server->adopted_at || $server->baseline_status !== 'verified' ||
            ! ($preview['applicable'] ?? false) || ($preview['blocked'] ?? 1) !== 0 ||
            ($preview['policy_revision'] ?? null) !== $server->policy_revision ||
            ($preview['from'] ?? null) !== $server->installed_version || ($preview['to'] ?? null) !== $server->targetVersion() ||
            ! $server->last_preview_at || $server->last_preview_at->lt(now()->subMinutes(15)) ||
            ($server->backup_mode ?? 'pelican') !== 'pelican') {
            throw new RuntimeException('A current conflict-free qualified plan, verified adoption and Pelican backup backend are required. Compare again before approval.');
        }
    }

    public function require(ManagedServer $server, ?string $approvedDigest): void
    {
        if (isset($server->baseline_provenance['transport'])) {
            $this->requireReady($server);
        }
        if (! in_array($server->transport, ['local', 'native'], true)) {
            throw new RuntimeException('Remote deployment is disabled: a qualified node transaction and exclusion-preserving restore are not available');
        }
        if (($server->runtime_report['world_path_verified'] ?? null) === false) {
            throw new RuntimeException('World directory path requires manual verification before deployment');
        }
        if (! $server->adopted_at || $server->baseline_status !== 'verified') {
            throw new RuntimeException('Verified adoption is required before deployment');
        }
        $preview = $server->preview_summary ?? [];
        if (($preview['policy_revision'] ?? null) !== $server->policy_revision || ! ($preview['applicable'] ?? false) || ! $approvedDigest ||
            ! hash_equals($server->approved_plan_sha256 ?? '', $approvedDigest) ||
            ! hash_equals($preview['plan_sha256'] ?? '', $approvedDigest) ||
            ! $server->approved_at || $server->approved_at->lt(now()->subMinutes(30))) {
            throw new RuntimeException('A current, compatible and explicitly approved exact plan is required');
        }
    }
}
