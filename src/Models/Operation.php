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

    protected function casts(): array
    {
        return ['context' => 'encrypted:array', 'progress' => 'array', 'cancel_requested_at' => 'datetime', 'heartbeat_at' => 'datetime', 'completed_at' => 'datetime'];
    }
}
