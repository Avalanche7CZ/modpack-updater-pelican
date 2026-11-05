<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('modpack_manager_servers', function (Blueprint $table): void {
            $table->string('backup_mode', 16)->default('pelican');
            $table->uuid('last_backup_uuid')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('modpack_manager_servers', function (Blueprint $table): void {
            $table->dropColumn(['backup_mode', 'last_backup_uuid']);
        });
    }
};
