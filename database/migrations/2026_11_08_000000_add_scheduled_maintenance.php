<?php

declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('arcturus_modpack_servers', function (Blueprint $table): void {
            $table->string('maintenance_time', 5)->nullable();
            $table->string('maintenance_timezone', 64)->default('UTC');
            $table->boolean('restart_when_current')->default(false);
            $table->unsignedBigInteger('automation_actor_id')->nullable();
            $table->date('last_maintenance_on')->nullable();
        });
    }
    public function down(): void
    {
        Schema::table('arcturus_modpack_servers', fn (Blueprint $table) => $table->dropColumn([
            'maintenance_time', 'maintenance_timezone', 'restart_when_current', 'automation_actor_id', 'last_maintenance_on',
        ]));
    }
};
