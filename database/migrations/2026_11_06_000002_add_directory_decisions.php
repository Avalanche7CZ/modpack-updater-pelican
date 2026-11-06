<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('arcturus_modpack_servers', fn (Blueprint $table) => $table->json('directory_decisions')->nullable());
    }

    public function down(): void
    {
        Schema::table('arcturus_modpack_servers', fn (Blueprint $table) => $table->dropColumn('directory_decisions'));
    }
};
