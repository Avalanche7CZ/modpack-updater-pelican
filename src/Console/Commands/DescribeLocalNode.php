<?php

declare(strict_types=1);

namespace Arcturus\ModpackManager\Console\Commands;

use App\Models\Node;
use App\Models\Server;
use Illuminate\Console\Command;
use Arcturus\ModpackManager\Models\Operation;
use Arcturus\ModpackManager\Models\ManagedServer;
use Arcturus\ModpackManager\Local\LocalNode;

/** Invoked by the administrator setup tool as the unprivileged Panel account. Read-only. */
final class DescribeLocalNode extends Command
{
    protected $signature = 'arcturus-modpack:describe-local-node {node : Pelican node ID} {--check : Verify installed host readiness}';

    protected $description = 'Export non-secret local node identity and server UUIDs for host approval';

    public function handle(): int
    {
        $node = Node::findOrFail((int) $this->argument('node'));
        if ($this->option('check')) {
            $status = app(LocalNode::class)->status();
            $this->line(json_encode($status, JSON_THROW_ON_ERROR));

            return $status['ready'] ? self::SUCCESS : self::FAILURE;
        }
        $uuids = Server::where('node_id', $node->id)->pluck('uuid')->all();
        $this->line(json_encode(['node_id' => $node->id, 'node_uuid' => $node->uuid,
            'volume_root' => $node->daemon_base,
            'servers' => $uuids,
            'active_operations' => Operation::whereNull('completed_at')->whereIn('managed_server_id',
                ManagedServer::whereIn('pelican_server_uuid', $uuids)->select('id'))->count(),
            'queue_driver' => config('queue.connections.'.config('queue.default').'.driver')], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
