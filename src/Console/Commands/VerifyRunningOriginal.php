<?php

declare(strict_types=1);
namespace Arcturus\ModpackManager\Console\Commands;

use Arcturus\ModpackManager\Native\RunningOriginal;
use Illuminate\Console\Command;

final class VerifyRunningOriginal extends Command
{
    protected $signature = 'arcturus-modpack:verify-running-original {operation} {--apply : Close verified recovery and sync Wings} {--actor= : Root administrator user ID for apply} {--sync-only : Retry a previously verified configuration release}';
    protected $description = 'Inspect a running original installation without power, restore or volume writes; explicit --apply releases verified recovery';

    public function handle(RunningOriginal $verifier): int
    {
        try {
            $result = $verifier->run((string)$this->argument('operation'), (bool)$this->option('apply'),
                $this->option('actor') ? (int)$this->option('actor') : null, (bool)$this->option('sync-only'));
            $this->info($result['message']); return self::SUCCESS;
        } catch (\Throwable $error) {
            report($error);
            $this->error(get_class($error) === \RuntimeException::class ? $error->getMessage() : 'Verification/sync unavailable; no power or restore was requested. Inspect the private Panel log. If already restored, retry with --sync-only --apply.');
            return self::FAILURE;
        }
    }
}
