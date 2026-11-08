<?php

declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('arcturus_modpack_schedule_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('dispatch_key', 191)->unique();
            $table->unsignedBigInteger('task_id')->index();
            $table->unsignedBigInteger('managed_server_id');
            $table->json('task_snapshot');
            $table->boolean('manual_run')->default(false);
            $table->string('state', 24)->default('queued');
            $table->uuid('operation_id')->nullable();
            $table->unsignedBigInteger('policy_revision');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('completed_at')->nullable()->index();
            $table->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('arcturus_modpack_schedule_runs'); }
};
