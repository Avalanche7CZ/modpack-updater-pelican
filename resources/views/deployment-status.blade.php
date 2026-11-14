<div wire:poll.3s.visible>
    @if($operation)
        <x-filament::section heading="Manual deployment">
            <p class="font-medium">{{ str_replace('_', ' ', ucfirst($operation->phase)) }} · {{ $context['from'] ?? '' }} → {{ $context['to'] ?? '' }}</p>
            @if(!$receiptReadable)
                <p class="mt-2 text-sm">The encrypted deployment receipt cannot be read. The displayed phase is the last recorded database state, not confirmation that deployment succeeded. Do not repeat the update or delete its recovery evidence. Check Panel and queue logs and the original APP_KEY configuration.</p>
            @endif
            <p class="mt-2 text-sm">{{ $operation->progress['message'] ?? 'Waiting for a queue worker.' }}</p>
            @if(in_array($operation->phase, ['verifying_before_backup', 'verifying_after_backup', 'applying'], true) && is_int($operation->progress['files_done'] ?? null) && is_int($operation->progress['files_total'] ?? null))
                <p class="mt-2 text-sm">{{ number_format($operation->progress['files_done'], 0, '.', ' ') }} / {{ number_format($operation->progress['files_total'], 0, '.', ' ') }} {{ $operation->phase === 'applying' ? 'confirmed for this step.' : 'paths verified (including expected absence).' }}</p>
            @endif
            @if($receiptReadable && is_array($context['native_metrics'] ?? null))
                @php($metrics = $context['native_metrics'])
                <p class="mt-2 text-sm">Recorded elapsed: {{ number_format($metrics['elapsed_seconds'] ?? 0, 1) }} s. Measurements update at checkpoints and periodic heartbeats.</p>
                @if(is_numeric($metrics['offline_to_verified_boot_seconds'] ?? null))
                    <p class="mt-2 text-sm">Confirmed offline → verified Minecraft boot: {{ number_format($metrics['offline_to_verified_boot_seconds'], 1) }} s.</p>
                @endif
                <details class="mt-2 text-sm">
                    <summary>Native deployment measurements</summary>
                    <p class="mt-2">Export: <code>php artisan arcturus-modpack:native-measurements {{ $operation->id }}</code></p>
                    <div class="overflow-x-auto">
                        <table class="mt-2 w-full text-left">
                            <thead><tr><th>Work</th><th>Calls</th><th>Seconds</th><th>Transferred MiB</th><th>Failed</th></tr></thead>
                            <tbody>
                                @foreach(($metrics['work'] ?? []) as $name => $sample)
                                    <tr><td>{{ str_replace('-', ' ', $name) }}</td><td>{{ number_format($sample['calls'] ?? 0) }}</td><td>{{ number_format($sample['seconds'] ?? 0, 2) }}</td><td>{{ in_array($name, ['upload', 'hash-read', 'text-read'], true) ? number_format(($sample['bytes'] ?? 0) / 1048576, 2) : '—' }}</td><td>{{ number_format($sample['failed'] ?? 0) }}</td></tr>
                                @endforeach
                            </tbody>
                        </table>
                        <table class="mt-2 w-full text-left">
                            <thead><tr><th>Phase</th><th>Elapsed seconds</th></tr></thead>
                            <tbody>
                                @foreach(($metrics['phases_seconds'] ?? []) as $name => $seconds)
                                    <tr><td>{{ str_replace(['applying.', '_'], ['', ' '], $name) }}</td><td>{{ number_format($seconds, 2) }}</td></tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </details>
            @endif
            @if($receiptReadable && $operation->phase === 'completed')<p class="mt-2 text-sm">Update completed. Minecraft reached a fresh startup marker.</p>@endif
            @if($receiptReadable && $operation->phase === 'restored')
                <p class="mt-2 text-sm">{{ isset($context['native_manual_start_verification']) ? 'Recovery closed: restored managed files and a fresh original pack boot with administrator-approved launcher settings verified. Original receipt and manual repair audit retained.' : 'Update failed. Pelican confirmed the native restore; managed file hashes, original launcher settings and Minecraft startup were verified.' }}</p>
            @endif
            @if($receiptReadable && (($context['running_original_sync_pending'] ?? false) === true))<p class="mt-2 text-sm text-amber-600">Original installation verified; Wings configuration release is pending. Use Retry verified recovery sync. No restore or restart is needed.</p>@endif
            @if($operation->phase === 'awaiting_manual_start')
                <p class="mt-2 text-sm text-amber-600">{{ ($context['native_manual_start']['role'] ?? '') === 'deploy' ? 'Nasazeno, start neověřen.' : 'Soubory obnoveny, původní start neověřen.' }} Use normal Pelican power controls and launcher settings, then Verify running server. The active checkpoint and locked backup block another update. A manual crash does not initiate rollback.</p>
            @endif
            @if($operation->error)<p class="mt-2 break-words text-sm">{{ $operation->error }}</p>@endif
            @if(isset($context['native_target_boot_failure']) || isset($context['native_rollback_boot_failure']) || isset($context['native_recovery_boot_failure']))
                <p class="mt-2 text-sm">Private startup diagnostics: <code>php artisan arcturus-modpack:boot-diagnostics {{ $operation->id }}</code></p>
            @endif
            @if($operation->phase === 'manual_intervention' && ($context['native_auto_restore_suppressed'] ?? false) === true)
                <p class="mt-2 text-sm text-amber-600">Nasazení nedokončeno, čeká na admina. Automatic restore was disabled in the approved operation. Maintenance, checkpoint, logs and the locked backup remain; use Recover original installation only to explicitly request rollback.</p>
            @endif
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
