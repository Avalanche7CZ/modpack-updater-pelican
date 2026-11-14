<?php

declare(strict_types=1);
namespace Arcturus\ModpackManager\Native;

use Arcturus\ModpackManager\Models\Operation;
use RuntimeException;

/** An approval-scoped opt-out; explicit rollback authority belongs to one recovery execution. */
final class RestorePolicy
{
    public const WAITING = 'Nasazení nedokončeno, čeká na admina';

    public static function automaticRestoreDisabled(Operation $operation): bool
    {
        $context = $operation->context;
        return ($context['protocol'] ?? '') === Qualification::PROTOCOL
            && ($context['manual_approval'] ?? false) === true && ($context['automatic_approval'] ?? false) === false
            && ($context['no_automatic_restore'] ?? false) === true;
    }

    public static function requireRecoveryApproval(Operation $operation, string $execution): void
    {
        if (! self::automaticRestoreDisabled($operation)) { return; }
        $context = $operation->context; $approval = $context['native_explicit_rollback'] ?? [];
        if (($approval['operation_id'] ?? null) !== $operation->id || ($approval['execution_id'] ?? null) !== $execution
            || ($approval['backup_uuid'] ?? null) !== ($context['backup_uuid'] ?? null) || empty($approval['backup_uuid'])
            || empty($approval['actor_id']) || ($approval['actor_id'] ?? null) !== ($context['recovery_actor_id'] ?? null)
            || ! \App\Models\User::find($approval['actor_id'])?->isRootAdmin()) {
            throw new RuntimeException('Automatic restore was disabled in this approval. An administrator must explicitly request rollback from this exact locked backup; a queued recovery job alone is not approval.');
        }
    }

    public static function retainFailure(Operation $operation): void
    {
        $operation->refresh();
        $operation->update(['progress' => ['message' => self::WAITING], 'context' => [...$operation->context,
            'native_auto_restore_suppressed' => true, 'native_deploy_files_verified' => false]]);
    }
}
