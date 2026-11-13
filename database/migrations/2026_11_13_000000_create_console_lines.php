<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('arcturus_modpack_console_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('managed_server_id')->constrained('arcturus_modpack_servers')->cascadeOnDelete();
            $table->uuid('operation_id');
            $table->string('phase', 64);
            $table->string('message', 512);
            $table->timestamp('created_at');
            $table->index(['managed_server_id', 'id']);
            $table->index(['operation_id', 'id']);
        });
    }

    public function down(): void { Schema::dropIfExists('arcturus_modpack_console_lines'); }
};
