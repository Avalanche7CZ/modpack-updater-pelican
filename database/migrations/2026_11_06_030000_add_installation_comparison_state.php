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
            $table->string('comparison_status', 24)->default('incomplete');
            $table->json('comparison_diagnostic')->nullable();
        });
        // Preserve all verified manifests; only derive comparison state from existing evidence.
        DB::table('arcturus_modpack_servers')->orderBy('id')->chunkById(100, function ($servers): void {
            foreach ($servers as $server) {
                $report = json_decode($server->adoption_report ?? 'null', true);
                if (($report['verified_baseline'] ?? false) && ($report['policy_revision'] ?? null) === $server->policy_revision &&
                    ($report['version'] ?? null) === $server->installed_version) {
                    DB::table('arcturus_modpack_servers')->where('id', $server->id)->update(['comparison_status' => 'complete']);
                    continue;
                }
                $last = DB::table('arcturus_modpack_operations')->where('managed_server_id', $server->id)
                    ->whereIn('kind', ['baseline', 'comparison'])->orderByDesc('created_at')->orderByDesc('id')->first();
                $progress = json_decode($last->progress ?? 'null', true);
                if ($last && $last->phase === 'failed' && ($progress['current']['phase'] ?? null) === 'comparing') {
                    DB::table('arcturus_modpack_servers')->where('id', $server->id)->update(['comparison_status' => 'failed',
                        'comparison_diagnostic' => json_encode(['schema' => 1, 'code' => 'INVENTORY_FAILED', 'phase' => 'comparing'])]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('arcturus_modpack_servers', fn (Blueprint $table) => $table->dropColumn(['comparison_status', 'comparison_diagnostic']));
    }
};
