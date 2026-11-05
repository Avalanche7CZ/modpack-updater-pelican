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
            $table->string('transport', 16)->default('wings');
            $table->string('baseline_status', 24)->default('unverified');
            $table->json('baseline_provenance')->nullable();
            $table->json('runtime_report')->nullable();
            $table->json('adoption_report')->nullable();
            $table->json('release_metadata')->nullable();
            $table->string('selected_version', 120)->nullable();
            $table->json('target_metadata')->nullable();
            $table->json('disabled_mods')->nullable();
            $table->json('mod_identity_mappings')->nullable();
            $table->string('active_operation_id', 36)->nullable();
            $table->string('approved_plan_sha256', 64)->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('adopted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('last_check_attempt_at')->nullable();
        });
        Schema::create('arcturus_modpack_operations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('managed_server_id')->constrained('arcturus_modpack_servers')->restrictOnDelete();
            $table->string('kind', 24);
            $table->string('phase', 32)->default('queued');
            $table->string('node_operation_id', 32)->nullable();
            $table->string('backup_uuid', 36)->nullable();
            $table->text('context')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('heartbeat_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['managed_server_id', 'completed_at']);
        });
        Schema::create('arcturus_modpack_settings', function (Blueprint $table): void {
            $table->string('key', 64)->primary();
            $table->text('value');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('arcturus_modpack_settings');
        Schema::dropIfExists('arcturus_modpack_operations');
        Schema::table('arcturus_modpack_servers', fn (Blueprint $table) => $table->dropColumn([
            'transport', 'baseline_status', 'baseline_provenance', 'runtime_report', 'adoption_report',
            'release_metadata', 'selected_version', 'target_metadata', 'disabled_mods', 'mod_identity_mappings', 'active_operation_id', 'approved_plan_sha256',
            'approved_by', 'adopted_at', 'approved_at', 'last_check_attempt_at',
        ]));
    }
};
