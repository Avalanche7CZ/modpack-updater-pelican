<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('arcturus_modpack_servers', function (Blueprint $table): void {
            // NULL inherits defaults for servers already configured before this migration.
            $table->json('backup_excluded_directories')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('arcturus_modpack_servers', function (Blueprint $table): void {
            $table->dropColumn('backup_excluded_directories');
        });
    }
};
