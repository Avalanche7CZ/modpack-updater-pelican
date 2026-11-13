<?php

declare(strict_types=1);
namespace Arcturus\ModpackManager\Console\Commands;

use Arcturus\ModpackManager\Models\Operation;
use Arcturus\ModpackManager\Native\BootFailureEvidence;
use Illuminate\Console\Command;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Str;
use Symfony\Component\Console\Output\OutputInterface;

final class BootDiagnostics extends Command
{
    protected $signature = 'arcturus-modpack:boot-diagnostics {operation : Exact deployment operation UUID}';
    protected $description = 'Read private startup failure evidence for a deployment without contacting Wings';

    public function handle(): int
    {
        $id = (string) $this->argument('operation');
        $operation = Str::isUuid($id) ? Operation::query()->where('kind', 'deploy')->find($id) : null;
        if ($operation === null) { $this->error('Deployment operation not found.'); return self::FAILURE; }
        try { $context = $operation->context ?? []; }
        catch (DecryptException) { $this->error('The encrypted deployment receipt could not be read.'); return self::FAILURE; }

        $found = false;
        foreach ($context['native_boot_attempts'] ?? [] as $id => $attempt) {
            if (!is_string($id) || !preg_match('/^[a-z0-9-]{32,50}$/D', $id)) { continue; }
            $reason = match ($attempt['failure'] ?? '') { 'start_not_observed' => 'start not observed before timeout', 'startup_failed_after_observation' => 'startup failed after start observation', default => 'accepted/verified attempt' };
            $this->line('Boot attempt '.$id.' — '.$reason);
        }
        foreach (['target' => 'Target version', 'rollback' => 'Automatic rollback', 'recovery' => 'Manual recovery'] as $role => $label) {
            $evidence = $context['native_'.$role.'_boot_failure'] ?? null;
            if (! is_array($evidence)) { continue; }
            $found = true;
            $source = match ($evidence['source'] ?? null) {
                'wings_console' => 'Wings console (stdout/stderr)',
                'startup_log_sample' => 'last stable startup log sample',
                default => 'no attributable output available',
            };
            $this->line($label.' — '.$source);
            if (is_string($evidence['text'] ?? null) && $evidence['text'] !== '') {
                $this->output->writeln(BootFailureEvidence::safeTail($evidence['text']), OutputInterface::OUTPUT_RAW);
            }
        }
        if (! $found) { $this->line('No boot failure evidence was recorded for this operation.'); }
        return self::SUCCESS;
    }
}
