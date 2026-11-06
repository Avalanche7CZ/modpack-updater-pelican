<div wire:poll.3s.visible>
    @if($operation && in_array($operation->kind, ['baseline', 'prepare', 'comparison'], true))
        @php($terminal = $operation->completed_at !== null)
        <x-filament::section :heading="$operation->kind === 'comparison' ? 'Installation comparison' : ($terminal ? 'Last preparation' : 'Background operation')">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <x-filament::badge :color="$presentation['color']" :icon="$terminal ? ($operation->phase === 'completed' ? 'heroicon-m-check-circle' : 'heroicon-m-exclamation-circle') : null">
                    {{ $presentation['status'] }}
                </x-filament::badge>
                <span class="text-sm text-gray-500 dark:text-gray-400">Last report {{ $operation->heartbeat_at?->diffForHumans() ?? 'unavailable' }}</span>
            </div>
            @if(!$terminal && $operation->cancel_requested_at)
                <p class="mt-3 text-sm">Waiting for the worker to confirm cancellation. Partial staging will be retained.</p>
            @endif
            @if($report['current'] ?? [])
                <ol class="mt-4" aria-label="Preparation phases">
                    @foreach($presentation['rows'] as $row)
                        <li wire:key="preparation-phase-{{ $row['key'] }}" data-phase="{{ $row['key'] }}" class="border-t border-gray-200 py-3 dark:border-gray-700">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <span class="text-sm font-medium">{{ $row['label'] }}</span>
                                <x-filament::badge :color="$row['color']" :icon="$row['state'] === 'Completed' ? 'heroicon-m-check-circle' : null">{{ $row['state'] }}</x-filament::badge>
                            </div>
                            @if($row['active'] && $row['snapshot'])
                                @if($row['percent'] !== null)
                                    <progress class="mt-2 w-full" max="100" value="{{ $row['percent'] }}" aria-label="{{ $row['label'] }} progress"></progress>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ $row['percent'] }}% of this phase{{ !$row['fresh'] ? ' · last recorded sample' : '' }}</p>
                                @else
                                    <div class="mt-2 flex items-center gap-2 text-sm text-gray-500 dark:text-gray-400" data-progress="indeterminate">
                                        @if($row['fresh'])<x-filament::loading-indicator class="h-4 w-4" aria-hidden="true" />@endif
                                        <span>{{ $row['fresh'] ? 'Working — total not known yet' : 'Waiting for the next progress sample' }}</span>
                                    </div>
                                @endif
                            @endif
                            @if($row['metrics'])
                                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ implode(' · ', $row['metrics']) }}</p>
                            @endif
                            @if($row['download'])
                                <dl class="mt-2 flex flex-wrap gap-x-6 gap-y-2 text-sm">
                                    <div><dt class="text-gray-500 dark:text-gray-400">{{ $row['download']['live'] ? 'Download speed' : 'Last recorded speed' }}</dt><dd class="font-medium">{{ $row['download']['speed'] ?? 'Not recorded' }}</dd></div>
                                    <div><dt class="text-gray-500 dark:text-gray-400">{{ $row['download']['live'] ? 'ETA' : 'Last recorded ETA' }}</dt><dd class="font-medium">{{ $row['download']['eta'] ?? 'Not available' }}</dd></div>
                                    @if($row['download']['downloaded'] !== null)
                                        <div><dt class="text-gray-500 dark:text-gray-400">Total downloaded</dt><dd class="font-medium">{{ \Arcturus\ModpackManager\Services\PreparationProgress::size($row['download']['downloaded']) }}{{ $row['download']['total'] !== null ? ' / '.\Arcturus\ModpackManager\Services\PreparationProgress::size($row['download']['total']) : '' }}</dd></div>
                                    @endif
                                </dl>
                            @endif
                        </li>
                    @endforeach
                </ol>
            @elseif(isset($legacy['files_resolved']))
                <p class="mt-3 text-sm">{{ $legacy['files_resolved'] }} / {{ $legacy['required_files'] ?? 'unknown' }} required files resolved</p>
            @else
                <p class="mt-3 text-sm">{{ !$terminal && $operation->phase === 'queued' ? 'Waiting for a queue worker.' : 'No progress samples were recorded.' }}</p>
            @endif
            @if($diagnostic)
                <div class="mt-4 rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                    <p class="break-words text-sm font-medium">{{ $diagnostic['description'] }}</p>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $diagnostic['action'] }}</p>
                </div>
            @elseif($terminal && $operation->phase !== 'completed')
                <p class="mt-3 text-sm">No structured diagnostic was recorded. Use the operation IDs in technical details to inspect retained evidence.</p>
            @endif
            <details wire:key="preparation-details-{{ $operation->id }}" class="mt-4 border-t border-gray-200 pt-3 text-xs text-gray-500 dark:border-gray-700 dark:text-gray-400">
                <summary class="cursor-pointer font-medium">Technical details</summary>
                <div class="mt-2 space-y-2">
                    <p>Phase snapshots show the most recent recorded pass, not an overall percentage. Extraction and hashing have independent counters. Download hashes are calculated during transfer and compared before extraction.</p>
                    <p>Recorded speed and ETA are historical samples, not elapsed time or a current estimate. No estimate is added when none was recorded. Stale snapshots are not ongoing progress.</p>
                    @foreach($presentation['rows'] as $row)
                        @if($row['snapshot'])
                            <p>{{ $row['label'] }}{{ isset($row['snapshot']['artifact_index']) ? ' · artifact #'.$row['snapshot']['artifact_index'] : '' }}{{ !empty($row['snapshot']['step']) ? ' · '.str_replace('_', ' ', $row['snapshot']['step']) : '' }}{{ isset($row['snapshot']['bytes_done']) ? ' · '.number_format($row['snapshot']['bytes_done']).' recorded bytes' : '' }}</p>
                        @endif
                    @endforeach
                    @if($diagnostic)
                        <p>Error: {{ $diagnostic['code'] }} · phase: {{ $diagnostic['phase'] }}</p>
                        @foreach($diagnostic['context'] ?? [] as $key => $value)<p class="break-words">{{ str_replace('_', ' ', $key) }}: {{ $value }}</p>@endforeach
                    @endif
                    <p>Snapshots survive refreshes and worker restarts. Partial staging is retained on cancellation or failure.</p>
                    <p class="break-all">Panel operation: {{ $operation->id }} · Worker operation: {{ $operation->node_operation_id }}</p>
                </div>
            </details>
        </x-filament::section>
    @else
        <p class="text-sm text-gray-500">No active preparation. Check History for previous attempts.</p>
    @endif
</div>
