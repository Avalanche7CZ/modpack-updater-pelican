<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('modpack_manager_servers', function (Blueprint $table): void {
            $table->id();
            $table->string('label', 120);
            $table->string('pelican_server_uuid', 64)->unique();
            $table->string('provider', 32);
            $table->string('source', 255);
            $table->string('asset_pattern', 160)->nullable();
            $table->string('installed_version', 120);
            $table->string('available_version', 120)->nullable();
            $table->string('available_asset_url', 2048)->nullable();
            $table->string('available_asset_sha256', 64)->nullable();
            $table->string('available_release_url', 2048)->nullable();
            $table->string('policy', 16)->default('notify');
            $table->boolean('enabled')->default(true);
            $table->json('file_rules')->nullable();
            $table->string('worker_key', 120)->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->index(['enabled', 'provider']);
        });
        Schema::create('modpack_manager_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('managed_server_id')->constrained('modpack_manager_servers')->cascadeOnDelete();
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
        Schema::dropIfExists('modpack_manager_events');
        Schema::dropIfExists('modpack_manager_servers');
    }
};
