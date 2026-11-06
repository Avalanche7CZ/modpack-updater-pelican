<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('arcturus_modpack_operations', function (Blueprint $table): void {
            $table->json('progress')->nullable();
            $table->timestamp('cancel_requested_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('arcturus_modpack_operations', fn (Blueprint $table) => $table->dropColumn(['progress', 'cancel_requested_at']));
    }
};
