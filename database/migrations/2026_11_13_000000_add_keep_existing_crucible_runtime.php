<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('arcturus_modpack_servers', function (Blueprint $table): void {
            $table->boolean('keep_existing_crucible_runtime')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('arcturus_modpack_servers', function (Blueprint $table): void {
            $table->dropColumn('keep_existing_crucible_runtime');
        });
    }
};
