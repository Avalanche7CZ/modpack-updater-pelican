<?php

declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('arcturus_modpack_servers', function (Blueprint $table): void {
            $table->json('file_inventory')->nullable();
            $table->unsignedInteger('policy_revision')->default(0);
        });
        // Existing rule JSON is preserved. Old previews used a different policy engine.
        DB::table('arcturus_modpack_servers')->update([
            'preview_summary' => null, 'approved_plan_sha256' => null, 'approved_at' => null, 'approved_by' => null,
        ]);
    }

    public function down(): void
    {
        Schema::table('arcturus_modpack_servers', fn (Blueprint $table) => $table->dropColumn(['file_inventory', 'policy_revision']));
    }
};
