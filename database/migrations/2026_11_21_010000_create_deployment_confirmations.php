<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('arcturus_modpack_deploy_confirmations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('deployment_id');
            $table->foreign('deployment_id', 'arcturus_confirmation_deploy_fk')->references('id')->on('arcturus_modpack_operations')->cascadeOnDelete();
            $table->unsignedBigInteger('review_event_id')->unique();
            $table->unsignedBigInteger('actor_id');
            $table->boolean('configuration_set');
            $table->char('proof', 64);
            $table->char('checkpoint_sha256', 64);
            $table->string('target_version', 120);
            $table->timestamp('admitted_at');
            $table->string('state', 24);
            $table->timestamp('dispatched_at')->nullable();
            $table->uuid('attempt_id')->nullable();
            $table->timestamp('attempt_started_at')->nullable();
            $table->string('worker', 255)->nullable();
            $table->uuid('finalization_attempt_id')->nullable();
            $table->string('finalization_worker', 255)->nullable();
            $table->timestamp('finalization_started_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
            $table->index(['deployment_id', 'review_event_id'], 'arcturus_confirmation_deploy_review_idx');
            $table->index(['state', 'updated_at'], 'arcturus_confirmation_state_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('arcturus_modpack_deploy_confirmations');
    }
};
