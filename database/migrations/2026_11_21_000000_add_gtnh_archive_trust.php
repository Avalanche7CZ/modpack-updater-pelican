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
            $table->string('gtnh_trust_mode', 16)->default('strict');
        });
        Schema::create('arcturus_modpack_archive_trust', function (Blueprint $table): void {
            $table->char('id', 64)->primary();
            $table->string('provider', 32);
            $table->string('version', 120);
            $table->string('variant', 24);
            $table->string('url', 512);
            $table->string('filename', 255);
            $table->char('sha256', 64);
            $table->unsignedBigInteger('size');
            $table->string('level', 24);
            $table->string('provenance', 64);
            $table->uuid('measurement_operation_id');
            $table->char('worker_operation_id', 32);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->timestamp('verified_at');
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('arcturus_modpack_archive_trust');
        Schema::table('arcturus_modpack_servers', fn (Blueprint $table) => $table->dropColumn('gtnh_trust_mode'));
    }
};
