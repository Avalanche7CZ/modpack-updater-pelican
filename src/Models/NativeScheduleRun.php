<?php

declare(strict_types=1);
namespace Arcturus\ModpackManager\Models;

final class NativeScheduleRun extends \Illuminate\Database\Eloquent\Model
{
    protected $table = 'arcturus_modpack_schedule_runs';
    protected $guarded = [];
    public $incrementing = false;
    protected $keyType = 'string';
    protected function casts(): array
    {
        return ['policy_revision' => 'integer', 'actor_id' => 'integer', 'task_id' => 'integer', 'managed_server_id' => 'integer', 'manual_run' => 'boolean', 'task_snapshot' => 'array', 'completed_at' => 'datetime'];
    }
}
