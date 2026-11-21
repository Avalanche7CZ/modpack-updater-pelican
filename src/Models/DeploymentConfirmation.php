<?php

declare(strict_types=1);

namespace Arcturus\ModpackManager\Models;

use Illuminate\Database\Eloquent\Model;

final class DeploymentConfirmation extends Model
{
    protected $table = 'arcturus_modpack_deploy_confirmations';
    protected $guarded = [];
    protected $hidden = ['proof', 'attempt_id', 'worker', 'finalization_attempt_id', 'finalization_worker'];
    public $incrementing = false;
    protected $keyType = 'string';

    protected function casts(): array
    {
        return ['admitted_at' => 'datetime', 'attempt_started_at' => 'datetime', 'finalization_started_at' => 'datetime', 'configuration_set' => 'boolean', 'actor_id' => 'integer'];
    }
}
