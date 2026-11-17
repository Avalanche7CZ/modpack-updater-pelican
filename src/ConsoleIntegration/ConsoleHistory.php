<?php

declare(strict_types=1);

namespace Arcturus\ModpackManager\ConsoleIntegration;

use Arcturus\ModpackManager\Models\Operation;
use Illuminate\Support\Facades\DB;

/** Durable, bounded-rate display projection of existing operation receipts. No deployment decisions live here. */
final class ConsoleHistory
{
    private const TABLE = 'arcturus_modpack_console_lines';

    public function record(Operation $operation): void
    {
        DB::transaction(function () use ($operation): void {
            // Serializes heartbeat/progress writers and retries using the authoritative persisted receipt.
            $receipt = Operation::whereKey($operation->id)->lockForUpdate()->first();
            if (! $receipt) { return; }
            try { $context = in_array($receipt->phase, ['completed', 'restored'], true) ? ($receipt->context ?? []) : []; }
            catch (\Illuminate\Contracts\Encryption\DecryptException) { $context = []; }
            $line = ConsoleLine::make($receipt->kind, $receipt->phase, $receipt->progress ?? [], $context);
            if (! $line) { return; }
            $last = DB::table(self::TABLE)->where('operation_id', $receipt->id)->latest('id')->first();
            if ($last && $last->phase === $line['phase'] &&
                ($last->message === $line['message'] || now()->diffInSeconds(\Illuminate\Support\Carbon::parse($last->created_at), true) < 15)) { return; }
            DB::table(self::TABLE)->insert([...$line, 'managed_server_id' => $receipt->managed_server_id,
                'operation_id' => $receipt->id, 'created_at' => now()]);
        });
    }

    /** The server console is live status, not the administrator's operation history. */
    public static function live(int $serverId, ?string $operationId, ?int $after): array
    {
        $operation = $operationId ? Operation::where('managed_server_id', $serverId)
            ->whereIn('kind', ['prepare', 'baseline', 'comparison', 'deploy'])
            ->whereNotIn('phase', ['completed', 'restored', 'cancelled'])
            ->find($operationId, ['id']) : null;

        if (! $operation) {
            return ['operation_id' => null, 'lines' => [], 'cursor' => (int) ($after ?? 0)];
        }

        return ['operation_id' => $operation->id, ...self::read($serverId, $after, $operation->id)];
    }

    public static function read(int $serverId, ?int $after, ?string $operationId = null): array
    {
        // On first connection after an upgrade, show the latest real receipt even if it predates this projection.
        // This records its current state only; it never invents missing historical transitions.
        if ($after === null) {
            $operation = Operation::where('managed_server_id', $serverId)
                ->when($operationId !== null, fn ($query) => $query->whereKey($operationId))->latest('created_at')->first();
            if ($operation && ! DB::table(self::TABLE)->where('operation_id', $operation->id)->exists()) {
                (new self)->record($operation);
            }
        }
        $query = DB::table(self::TABLE)->where('managed_server_id', $serverId)
            ->when($operationId !== null, fn ($query) => $query->where('operation_id', $operationId))->select(['id', 'message']);
        $rows = $after === null ? $query->latest('id')->limit(40)->get()->reverse()->values()
            : $query->where('id', '>', $after)->orderBy('id')->limit(100)->get();
        return ['lines' => $rows->map(fn ($row) => ['id' => (int) $row->id, 'text' => $row->message])->all(),
            'cursor' => (int) ($rows->last()?->id ?? $after ?? 0)];
    }
}
