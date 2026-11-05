<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // MySQL/MariaDB utf8mb4: eight VARCHAR(2048) fields alone can exceed
        // the 65,535-byte maximum logical row size (ERROR 1118). TEXT columns
        // keep long URLs/paths without forcing the bytes into the row budget.
        Schema::create('arcturus_modpack_servers', function (Blueprint $table): void {
            $table->id();
            $table->string('label', 120);
            $table->string('pelican_server_uuid', 64)->unique();
            $table->string('provider', 32);
            $table->string('source', 255);
            $table->string('asset_pattern', 160)->nullable();
            $table->string('installed_version', 120);
            $table->string('available_version', 120)->nullable();
            $table->text('available_asset_url')->nullable();
            $table->string('available_asset_sha256', 64)->nullable();
            $table->text('available_release_url')->nullable();
            $table->string('policy', 16)->default('notify');
            $table->boolean('enabled')->default(true);
            $table->boolean('deploy_enabled')->default(false);
            $table->json('file_rules')->nullable();
            $table->string('worker_key', 120)->nullable();
            $table->text('local_root')->nullable();
            $table->text('base_manifest_path')->nullable();
            $table->text('target_manifest_path')->nullable();
            $table->text('staging_parent')->nullable();
            $table->text('prepared_root')->nullable();
            $table->text('backup_root')->nullable();
            $table->string('artifact_sha256', 64)->nullable();
            $table->json('preview_summary')->nullable();
            $table->timestamp('last_preview_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->index(['enabled', 'provider']);
        });
        Schema::create('arcturus_modpack_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('managed_server_id')->constrained('arcturus_modpack_servers')->cascadeOnDelete();
            $table->string('kind', 32);
            $table->string('status', 24)->default('info');
            $table->string('from_version', 120)->nullable();
            $table->string('to_version', 120)->nullable();
            $table->text('message')->nullable();
            $table->json('details')->nullable();
            $table->timestamps();
            $table->index(['managed_server_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('arcturus_modpack_events');
        Schema::dropIfExists('arcturus_modpack_servers');
    }
};
