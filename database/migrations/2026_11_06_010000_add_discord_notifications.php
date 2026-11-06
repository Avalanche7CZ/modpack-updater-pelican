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
            $table->boolean('discord_enabled')->default(true);
            $table->text('discord_webhook')->nullable();
            $table->string('discord_role_id', 20)->nullable();
            $table->json('discord_notify_events')->nullable();
        });
        Schema::create('arcturus_modpack_discord_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('managed_server_id')->constrained('arcturus_modpack_servers')->cascadeOnDelete();
            $table->foreignId('source_event_id')->unique()->constrained('arcturus_modpack_events')->cascadeOnDelete();
            $table->string('kind', 24);
            $table->string('status', 24)->default('queued');
            $table->text('webhook')->nullable();
            $table->text('payload')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->string('message_id', 20)->nullable();
            $table->string('last_error', 200)->nullable();
            $table->timestamps();
            $table->index(['status', 'next_attempt_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('arcturus_modpack_discord_deliveries');
        Schema::table('arcturus_modpack_servers', fn (Blueprint $table) => $table->dropColumn(['discord_enabled', 'discord_webhook', 'discord_role_id', 'discord_notify_events']));
    }
};
