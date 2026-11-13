<?php

declare(strict_types=1);
namespace Arcturus\ModpackManager\Services;

use Arcturus\ModpackManager\Models\Operation;

/** Optional display state, never recovery evidence or authorization. */
final class ApplyProgress
{
    public const STEPS = [
        'stage_verification' => 'Verifying prepared files',
        'live_verification' => 'Checking live files before staging',
        'permissions' => 'Reading existing file permissions',
        'upload' => 'Uploading and verifying Wings staging files',
        'stage_permissions' => 'Setting Wings staging permissions',
        'live_recheck' => 'Rechecking live files after upload',
        'deploy' => 'Deploying approved paths',
        'final_verification' => 'Verifying final deployed paths',
    ];

    public function reporter(Operation $operation): \Closure
    {
        $previous = null; $last = 0.0;
        return function (string $step, int $done, int $total) use ($operation, &$previous, &$last): void {
            if (! isset(self::STEPS[$step]) || $done < 0 || $done > $total) { return; }
            if ($previous === $step && $done !== $total && microtime(true) - $last < 5) { return; }
            try {
                $operation->update(['progress' => ['step' => $step, 'message' => self::STEPS[$step], 'files_done' => $done, 'files_total' => $total], 'heartbeat_at' => now()]);
                $previous = $step; $last = microtime(true);
            } catch (\Throwable $error) { report($error); }
        };
    }
}
