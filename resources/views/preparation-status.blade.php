<div wire:poll.3s.visible>
    @if($operation && in_array($operation->kind, ['baseline', 'prepare'], true))
        @php($current = $report['current'] ?? [])
        @php($phase = $current['phase'] ?? $operation->phase)
        @php($labels = ['queued' => 'Waiting for queue worker', 'downloading' => 'Downloading', 'verifying' => 'Checksum verification', 'extracting' => 'Extraction', 'hashing' => 'Hashing staged files', 'comparing' => 'Inventory comparison', 'awaiting_resolution' => 'Resolving required files'])
        @php($terminal = $operation->completed_at !== null)
        @php($percent = \Arcturus\ModpackManager\Services\PreparationProgress::percentage($current))
        <x-filament::section :heading="$terminal ? 'Last preparation' : 'Background operation'">
            <p class="font-medium">{{ $terminal ? ucfirst(str_replace('_', ' ', $operation->phase)) : ($labels[$phase] ?? 'Preparing') }}</p>
            @if(!$terminal && $operation->cancel_requested_at)
                <p class="mt-2 text-sm">Cancellation requested. Waiting for the worker/queue to confirm; partial staging will be retained.</p>
            @endif
            @if($current)
                <p class="mt-2 text-sm">{{ $labels[$phase] ?? 'Preparing' }}{{ isset($current['artifact_index']) ? ' · artifact #'.$current['artifact_index'] : '' }}{{ !empty($current['step']) ? ' · '.str_replace('_', ' ', $current['step']) : '' }}</p>
                <progress class="mt-2 w-full" max="100" @if($percent !== null) value="{{ $percent }}" @endif aria-label="Preparation phase progress"></progress>
                <p class="text-sm">{{ $percent !== null ? $percent.'% of this phase' : 'Indeterminate — total not known' }}</p>
                @if(isset($current['bytes_done']))
                    <p class="mt-2 text-sm">{{ \Arcturus\ModpackManager\Services\PreparationProgress::size($current['bytes_done']) }} / {{ \Arcturus\ModpackManager\Services\PreparationProgress::size($current['bytes_total'] ?? null) }} ({{ number_format($current['bytes_done']) }} bytes)</p>
                @endif
                @if($phase === 'downloading')
                    @php($fresh = !$terminal && isset($current['updated_at']) && abs(time() - $current['updated_at']) <= 15)
                    <p class="text-sm">Download speed: {{ $fresh && isset($current['speed_bytes_per_second']) ? number_format($current['speed_bytes_per_second'] / 1048576, 2).' MiB/s' : 'Awaiting a current sample' }} · ETA: {{ $fresh && isset($current['eta_seconds']) ? ($current['eta_seconds'] > 86400 ? 'More than 24h' : '~'.gmdate('H:i:s', (int) ceil($current['eta_seconds']))) : 'Unknown' }}</p>
                    @if(isset($current['downloaded_bytes']))<p class="text-sm">Total downloaded: {{ \Arcturus\ModpackManager\Services\PreparationProgress::size($current['downloaded_bytes']) }} / {{ \Arcturus\ModpackManager\Services\PreparationProgress::size($current['download_total_bytes'] ?? null) }}</p>@endif
                @endif
                @if(isset($current['files_done']))<p class="text-sm">{{ $current['files_done'] }} / {{ $current['files_total'] ?? 'unknown' }} {{ $phase === 'verifying' ? 'checksums compared (hashes computed during transfer)' : 'files processed' }}</p>@endif
                @if(isset($current['entries_scanned']))<p class="text-sm">{{ $current['entries_scanned'] }} entries scanned</p>@endif
                <details class="mt-3 text-sm"><summary>Recorded phases</summary>
                    @foreach($report['stages'] ?? [] as $stage => $snapshot)
                        <p>{{ $labels[$stage] ?? $stage }} · {{ ($snapshot['finished'] ?? false) ? 'Finished' : 'Last recorded snapshot' }}{{ isset($snapshot['bytes_done']) ? ' · '.\Arcturus\ModpackManager\Services\PreparationProgress::size($snapshot['bytes_done']) : '' }}{{ isset($snapshot['files_done']) ? ' · '.$snapshot['files_done'].' / '.($snapshot['files_total'] ?? 'unknown').' files/checks' : '' }}</p>
                    @endforeach
                </details>
            @elseif(isset($legacy['files_resolved']))
                <p>{{ $legacy['files_resolved'] }} / {{ $legacy['required_files'] ?? 'unknown' }} required files resolved</p>
            @else<p class="mt-2 text-sm">No progress sample yet. Check queue health if this remains queued.</p>@endif
            @if($diagnostic)
                <div class="mt-3"><p class="break-words">[{{ $diagnostic['code'] }}] {{ $diagnostic['description'] }}</p><p class="text-sm">Phase: {{ $diagnostic['phase'] }}</p>
                    @foreach($diagnostic['context'] ?? [] as $key => $value)<p class="break-words text-sm">{{ str_replace('_', ' ', $key) }}: {{ $value }}</p>@endforeach
                    <p class="mt-2 text-sm">{{ $diagnostic['action'] }}</p></div>
            @elseif($terminal && $operation->phase !== 'completed')
                <p class="mt-3 text-sm">No structured diagnostic was recorded by the older worker. Inspect retained evidence using these operation IDs; the original error may have been replaced by the older local helper.</p>
            @endif
            <p class="mt-3 text-sm">Last worker/queue report: {{ $operation->heartbeat_at?->diffForHumans() ?? 'Unknown' }}. Snapshots survive refreshes and worker restarts; a stale snapshot is not ongoing progress.</p>
            <p class="break-all text-xs">Panel operation: {{ $operation->id }} · Worker operation: {{ $operation->node_operation_id }}</p>
        </x-filament::section>
    @else
        <p class="text-sm text-gray-500">No active preparation. Check History for previous attempts.</p>
    @endif
</div>
