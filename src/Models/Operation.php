<?php

declare(strict_types=1);

namespace Arcturus\ModpackManager\Models;

use Illuminate\Database\Eloquent\Model;

final class Operation extends Model
{
    protected $table = 'arcturus_modpack_operations';

    protected $guarded = [];

    public $incrementing = false;

    protected $keyType = 'string';

    protected static function booted(): void
    {
        static::saving(function (self $operation): void {
            // Fail BEFORE SQL on an incompletely migrated node, even when MySQL
            // runs without strict mode and would otherwise silently truncate.
            $context = $operation->getAttributes()['context'] ?? '';
            if (! $operation->isDirty('context') || strlen($context) <= 65535 ||
                ! in_array($operation->getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) { return; }
            $type = $operation->getConnection()->getSchemaBuilder()->getColumnType($operation->getTable(), 'context');
            if (! in_array($type, ['mediumtext', 'longtext'], true)) {
                throw new \RuntimeException('Operation recovery evidence exceeds the old database column limit. Apply the Arcturus operation-context migration before deployment. No truncated receipt was written.');
            }
        });
    }

    protected function casts(): array
    {
        return ['context' => 'encrypted:array', 'progress' => 'array', 'cancel_requested_at' => 'datetime', 'heartbeat_at' => 'datetime', 'completed_at' => 'datetime'];
    }
}
