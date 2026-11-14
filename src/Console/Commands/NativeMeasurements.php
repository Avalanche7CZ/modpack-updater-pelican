<?php

declare(strict_types=1);
namespace Arcturus\ModpackManager\Console\Commands;

use Arcturus\ModpackManager\Models\Operation;
use Illuminate\Console\Command;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Str;
use Symfony\Component\Console\Output\OutputInterface;

final class NativeMeasurements extends Command
{
    protected $signature = 'arcturus-modpack:native-measurements {operation : Exact deployment operation UUID} {--flow=* : Explicit preparation/comparison operation UUIDs to include in a read-only flow export}';
    protected $description = 'Export recorded native deployment timings and counters without contacting Wings';

    public function handle(): int
    {
        $id = (string) $this->argument('operation');
        $operation = Str::isUuid($id) ? Operation::where('kind', 'deploy')->find($id) : null;
        if ($operation === null) { $this->error('Deployment operation not found.'); return self::FAILURE; }
        try { $context = $operation->context ?? []; }
        catch (DecryptException) { $this->error('The encrypted deployment receipt could not be read.'); return self::FAILURE; }
        if (! is_array($context['native_metrics'] ?? null)) { $this->error('No native measurements were recorded.'); return self::FAILURE; }
        $flow = (array) $this->option('flow');
        if ($flow !== []) {
            $ids = array_values(array_unique([$id, ...$flow]));
            foreach ($ids as $key) { if (! Str::isUuid($key)) { $this->error('Every flow ID must be an exact operation UUID.'); return self::FAILURE; } }
            $rows = Operation::where('managed_server_id', $operation->managed_server_id)->whereIn('id', $ids)->orderBy('created_at')->get();
            if ($rows->count() !== count($ids)) { $this->error('Flow operations must all belong to the same managed server.'); return self::FAILURE; }
            $export = $rows->map(fn ($row) => ['id' => $row->id, 'kind' => $row->kind, 'phase' => $row->phase,
                'created_at' => $row->created_at?->toISOString(), 'completed_at' => $row->completed_at?->toISOString(),
                'progress' => ['stages' => $row->progress['stages'] ?? null], 'context' => ['native_metrics' => $row->context['native_metrics'] ?? null]])->all();
            $this->output->writeln(json_encode($export, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);
            return self::SUCCESS;
        }
        $this->output->writeln(json_encode(['operation_id' => $operation->id, 'phase' => $operation->phase,
            'metrics' => $context['native_metrics'], 'transfer' => $context['native_transfer'] ?? null, 'apply_budget' => $context['native_apply_budget'] ?? null], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);
        return self::SUCCESS;
    }
}
