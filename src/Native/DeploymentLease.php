<?php

declare(strict_types=1);
namespace Arcturus\ModpackManager\Native;

use Illuminate\Support\Facades\{Cache, Log};

/** Same mutation lease and TTL; diagnostics never include cache owner tokens. */
final class DeploymentLease
{
    private object $lock;

    public function __construct(private readonly int $serverId, private readonly ?string $operationId, private readonly string $action, int $seconds = 14400)
    { $this->lock = Cache::lock('arcturus-modpack-manager-deploy-'.$serverId, $seconds); }

    public function get(): bool
    {
        try { $acquired = (bool) $this->lock->get(); }
        catch (\Throwable $error) { $this->log('acquire_failure', ['error_class' => get_class($error)]); throw $error; }
        $this->log($acquired ? 'acquired' : 'held');
        return $acquired;
    }

    public function isOwnedByCurrentProcess(): bool { return $this->lock->isOwnedByCurrentProcess(); }

    public function release(): bool
    {
        try {
            $owned = $this->lock->isOwnedByCurrentProcess();
            $released = (bool) $this->lock->release();
            $this->log($released ? 'released' : 'release_failure', ['owned_before_release' => $owned, 'release_result' => $released]);
            return $released;
        } catch (\Throwable $error) { $this->log('release_failure', ['error_class' => get_class($error)]); throw $error; }
    }

    private function log(string $event, array $extra = []): void
    {
        self::diagnostic($this->serverId, $this->operationId, $this->action, $event, $extra);
    }

    public static function diagnostic(int $serverId, ?string $operationId, string $action, string $event, array $extra = []): void
    {
        $data = ['event' => $event, 'managed_server_id' => $serverId, 'operation_id' => $operationId,
            'action' => $action, 'pid' => getmypid(), 'at_utc' => gmdate('Y-m-d\TH:i:s\Z'), ...$extra];
        // A log sink failure must never prevent releasing the mutation lease.
        try { Log::log(str_contains($event, 'failure') ? 'error' : 'info', 'Arcturus deployment lease', $data); }
        catch (\Throwable) { error_log('Arcturus deployment lease '.json_encode($data)); }
    }
}
