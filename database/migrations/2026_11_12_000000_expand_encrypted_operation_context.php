<?php
declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('arcturus_modpack_operations', function (Blueprint $table): void {
            $table->longText('context')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Never shrink recovery evidence back to MySQL TEXT's 65,535-byte limit.
        // The original table migration owns deletion when uninstalling the schema.
    }
};
