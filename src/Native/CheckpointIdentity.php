<?php

declare(strict_types=1);
namespace Arcturus\ModpackManager\Native;

use Arcturus\ModpackManager\Models\Operation;

final class CheckpointIdentity
{
    public static function of(Operation $operation): string
    {
        $context = $operation->context;
        // Observations are append-only evidence, not permission to change power, files or ownership.
        unset($context['native_manual_start']['console_evidence'], $context['native_metrics']);
        return hash('sha256', json_encode([$operation->id, $operation->managed_server_id, $operation->phase,
            $operation->completed_at?->toIso8601String(), $context], JSON_THROW_ON_ERROR));
    }
}
