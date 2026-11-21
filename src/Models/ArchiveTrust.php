<?php

declare(strict_types=1);

namespace Arcturus\ModpackManager\Models;

use Illuminate\Database\Eloquent\Model;

final class ArchiveTrust extends Model
{
    protected $table = 'arcturus_modpack_archive_trust';
    protected $guarded = [];
    public $incrementing = false;
    protected $keyType = 'string';

    protected function casts(): array
    {
        return ['size' => 'integer', 'verified_at' => 'datetime', 'decided_at' => 'datetime'];
    }
}
