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
    protected $signature = 'arcturus-modpack:native-measurements {operation : Exact deployment operation UUID}';
    protected $description = 'Export recorded native deployment timings and counters without contacting Wings';

    public function handle(): int
    {
        $id = (string) $this->argument('operation');
        $operation = Str::isUuid($id) ? Operation::where('kind', 'deploy')->find($id) : null;
        if ($operation === null) { $this->error('Deployment operation not found.'); return self::FAILURE; }
        try { $context = $operation->context ?? []; }
        catch (DecryptException) { $this->error('The encrypted deployment receipt could not be read.'); return self::FAILURE; }
        if (! is_array($context['native_metrics'] ?? null)) { $this->error('No native measurements were recorded.'); return self::FAILURE; }
        $this->output->writeln(json_encode(['operation_id' => $operation->id, 'phase' => $operation->phase,
            'metrics' => $context['native_metrics'], 'apply_budget' => $context['native_apply_budget'] ?? null], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);
        return self::SUCCESS;
    }
}
