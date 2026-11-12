<div wire:poll.3s.visible>
    @if($operation)
        <x-filament::section heading="Manual deployment">
            <p class="font-medium">{{ str_replace('_', ' ', ucfirst($operation->phase)) }} · {{ $context['from'] ?? '' }} → {{ $context['to'] ?? '' }}</p>
            @if(!$receiptReadable)
                <p class="mt-2 text-sm">The encrypted deployment receipt cannot be read. The displayed phase is the last recorded database state, not confirmation that deployment succeeded. Do not repeat the update or delete its recovery evidence. Check Panel and queue logs and the original APP_KEY configuration.</p>
            @endif
            <p class="mt-2 text-sm">{{ $operation->progress['message'] ?? 'Waiting for a queue worker.' }}</p>
            @if($receiptReadable && $operation->phase === 'completed')<p class="mt-2 text-sm">Update completed. Minecraft reached a fresh startup marker.</p>@endif
            @if($receiptReadable && $operation->phase === 'restored')<p class="mt-2 text-sm">Update failed. Pelican confirmed the native restore; managed file hashes, original launcher settings and Minecraft startup were verified.</p>@endif
            @if($operation->error)<p class="mt-2 break-words text-sm">{{ $operation->error }}</p>@endif
            @if($operation->phase === 'manual_intervention')
                <p class="mt-2 text-sm">Keep Minecraft stopped. Check the locked backup and retained deployment journal before restoring. Do not retry Apply Update or delete recovery evidence. If Wings could not confirm stopped state, stop the server through the node first. After an operator restores the correct snapshot and original launch settings, use Verify restored original snapshot; it never reapplies the update.</p>
            @endif
            @if($operation->phase === 'manual_intervention' && isset($context['panel_snapshot']['image']))<p class="mt-2 break-all text-sm">Original container image: {{ $context['panel_snapshot']['image'] }}. Full launcher settings are retained in the encrypted operation receipt.</p>@endif
            @if($context['backup_uuid'] ?? null)<p class="mt-2 break-all text-sm">Locked Pelican backup: {{ $context['backup_uuid'] }}</p>@endif
            @if($operation->phase === 'manual_intervention' && ($context['journal'] ?? null))<p class="mt-2 break-all text-sm">Retained deployment journal: {{ $context['journal'] }}</p>@endif
            @if($context['preserved_stash'] ?? null)<p class="mt-2 break-all text-sm">Preserved excluded folders: {{ $context['preserved_stash'] }}</p>@endif
            <p class="mt-2 text-xs">Operation: {{ $operation->id }} · Last report {{ $operation->heartbeat_at?->diffForHumans() ?? 'unavailable' }}</p>
        </x-filament::section>
    @endif
</div>
