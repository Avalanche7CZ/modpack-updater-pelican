<?php

declare(strict_types=1);

namespace Arcturus\ModpackManager\Services;

use Arcturus\ModpackManager\Models\ManagedServer;
use Arcturus\ModpackManager\Remote\ServerFilesFactory;
use RuntimeException;

final class MergeReview
{
    public function properties(ManagedServer $server, string $path): array
    {
        UpdatePlanner::assertPath($path);
        if (! str_ends_with($path, '.properties')) {
            throw new RuntimeException('Only strict single-line properties files support a deterministic merge');
        }
        $baseId = $server->baseline_provenance['node_operation_id'] ?? null;
        $targetId = $server->target_metadata['prepared']['node_operation_id'] ?? null;
        if (! $baseId || ! $targetId) {
            throw new RuntimeException('The original baseline and target node stages must both be retained');
        }
        $worker = app(ServerFilesFactory::class)->worker($server);
        $base = $worker->request('stage_file', ['operation_id' => $baseId, 'path' => $path]);
        $target = $worker->request('stage_file', ['operation_id' => $targetId, 'path' => $path]);
        $local = app(ServerFilesFactory::class)->wings($server->pelicanServer)->read($path);
        $result = (new PropertiesMerge)->merge($base['content'], $local, $target['content']);

        return [...$result, 'path' => $path, 'local_sha256' => hash('sha256', $local),
            'notice' => 'Review output only. No live file has been written. Apply the reviewed content through the native file editor.'];
    }
}
