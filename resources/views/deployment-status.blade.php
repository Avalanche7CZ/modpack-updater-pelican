<div wire:poll.3s.visible>
    @if($operation)
        <x-filament::section heading="Manual deployment">
            <p class="font-medium">{{ str_replace('_', ' ', ucfirst($operation->phase)) }} · {{ $operation->context['from'] ?? '' }} → {{ $operation->context['to'] ?? '' }}</p>
            <p class="mt-2 text-sm">{{ $operation->progress['message'] ?? 'Waiting for a queue worker.' }}</p>
            @if($operation->phase === 'completed')<p class="mt-2 text-sm">Update completed. Minecraft reached a fresh startup marker.</p>@endif
            @if($operation->phase === 'restored')<p class="mt-2 text-sm">Update failed. The complete original snapshot and its Minecraft startup were verified after rollback.</p>@endif
            @if($operation->error)<p class="mt-2 break-words text-sm">{{ $operation->error }}</p>@endif
            @if($operation->phase === 'manual_intervention')
                <p class="mt-2 text-sm">Keep Minecraft stopped. Check the locked backup and retained deployment journal before restoring. Do not retry Apply Update or delete recovery evidence. If Wings could not confirm stopped state, stop the server through the node first. After an operator restores the correct snapshot and original launch settings, use Verify restored original snapshot; it never reapplies the update.</p>
            @endif
            @if($operation->phase === 'manual_intervention' && isset($operation->context['panel_snapshot']['image']))<p class="mt-2 break-all text-sm">Original container image: {{ $operation->context['panel_snapshot']['image'] }}. Full launcher settings are retained in the encrypted operation receipt.</p>@endif
            @if($operation->context['backup_uuid'] ?? null)<p class="mt-2 break-all text-sm">Locked Pelican backup: {{ $operation->context['backup_uuid'] }}</p>@endif
            @if($operation->phase === 'manual_intervention' && ($operation->context['journal'] ?? null))<p class="mt-2 break-all text-sm">Retained deployment journal: {{ $operation->context['journal'] }}</p>@endif
            @if($operation->context['preserved_stash'] ?? null)<p class="mt-2 break-all text-sm">Preserved excluded folders: {{ $operation->context['preserved_stash'] }}</p>@endif
            <p class="mt-2 text-xs">Operation: {{ $operation->id }} · Last report {{ $operation->heartbeat_at?->diffForHumans() ?? 'unavailable' }}</p>
        </x-filament::section>
    @endif
</div>
