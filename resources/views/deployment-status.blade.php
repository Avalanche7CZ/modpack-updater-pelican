<div @if(($confirmationPending ?? false) || (($isActive ?? false) && !$operation->completed_at && !in_array($operation->phase, ['awaiting_manual_start', 'manual_intervention'], true))) wire:poll.3s.visible @endif>
    @if($operation)
        @php($phaseLabel = match ($operation->phase) { 'completed' => 'Update complete', 'awaiting_manual_start' => 'Waiting for startup confirmation', 'manual_intervention' => 'Recovery needs attention', 'restored' => 'Original installation restored', default => str_replace('_', ' ', ucfirst($operation->phase)) })
        @php($phaseColor = match ($operation->phase) { 'completed', 'restored' => 'success', 'manual_intervention', 'awaiting_manual_start' => 'warning', 'failed', 'interrupted' => 'danger', 'cancelled' => 'gray', default => 'primary' })
        <x-filament::section :heading="($isActive ?? false) ? 'Current deployment' : 'Last deployment'" icon="heroicon-o-arrow-path">
            <div class="flex flex-wrap items-center gap-3">
                <x-filament::badge :color="$phaseColor">{{ $phaseLabel }}</x-filament::badge>
                @if(isset($context['from'], $context['to']))<span class="font-semibold break-all">{{ $context['from'] }} → {{ $context['to'] }}</span>@endif
            </div>
            @if(!$receiptReadable)
                <p class="mt-2 text-sm">The encrypted deployment receipt cannot be read. The displayed phase is the last recorded database state, not confirmation that deployment succeeded. Do not repeat the update or delete its recovery evidence. Check Panel and queue logs and the original APP_KEY configuration.</p>
            @endif
            @if(!in_array($operation->phase, ['completed', 'restored', 'awaiting_manual_start', 'manual_intervention'], true))
                <p class="mt-3 text-sm">{{ $operation->progress['message'] ?? ($operation->phase === 'queued' ? 'Waiting for a queue worker.' : $phaseLabel) }}</p>
            @endif
            @if(in_array($operation->phase, ['verifying_before_backup', 'verifying_after_backup', 'applying'], true) && is_int($operation->progress['files_done'] ?? null) && is_int($operation->progress['files_total'] ?? null))
                @if($operation->progress['files_total'] > 0 && $operation->progress['files_done'] >= 0 && $operation->progress['files_done'] <= $operation->progress['files_total'])
                    <progress class="mt-3 w-full" max="{{ $operation->progress['files_total'] }}" value="{{ $operation->progress['files_done'] }}" aria-label="Current deployment step progress"></progress>
                @endif
                <p class="mt-2 text-sm">{{ number_format($operation->progress['files_done'], 0, '.', ' ') }} / {{ number_format($operation->progress['files_total'], 0, '.', ' ') }} {{ $operation->phase === 'applying' ? 'confirmed for this step.' : 'paths verified (including expected absence).' }}</p>
            @endif
            @if($receiptReadable && is_array($context['native_metrics'] ?? null))
                @php($metrics = $context['native_metrics'])
                <details class="mt-3 text-sm">
                    <summary class="cursor-pointer">Timing and measurements</summary>
                    <p class="mt-2">Recorded elapsed: {{ number_format($metrics['elapsed_seconds'] ?? 0, 1) }} s. Measurements update at checkpoints and periodic heartbeats.</p>
                    @if(is_numeric($metrics['offline_to_verified_boot_seconds'] ?? null))
                        <p class="mt-2">Confirmed offline → verified Minecraft boot: {{ number_format($metrics['offline_to_verified_boot_seconds'], 1) }} s.</p>
                    @endif
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
            @if($receiptReadable && $operation->phase === 'completed')
                <p class="mt-3 text-sm">{{ ($context['running_confirmed_by_admin'] ?? false) === true ? 'An administrator confirmed the server is running. Startup was not automatically verified.' : (($context['startup_verified'] ?? false) === true ? 'Minecraft startup was verified.' : 'Startup verification was not recorded.') }}</p>
            @endif
            @if($receiptReadable && $operation->phase === 'completed' && ($context['native_admin_running_confirmation']['accepted_local_configs'] ?? []))
                <details class="mt-2 text-sm"><summary>Přijaté lokální configy: {{ count($context['native_admin_running_confirmation']['accepted_local_configs']) }}</summary>
                    @foreach($context['native_admin_running_confirmation']['accepted_local_configs'] as $path => $acceptedConfig)
                        <p class="break-all">{{ $path }} · upstream {{ $acceptedConfig['expected_sha256'] }} · místní {{ $acceptedConfig['actual_sha256'] }} · admin {{ $acceptedConfig['actor_id'] }}</p>
                    @endforeach
                </details>
            @endif
            @if($receiptReadable && $operation->phase === 'restored')
                <p class="mt-2 text-sm">{{ isset($context['native_manual_start_verification']) ? 'Recovery closed: restored managed files and a fresh original pack boot with administrator-approved launcher settings verified. Original receipt and manual repair audit retained.' : 'Update failed. Pelican confirmed the native restore; managed file hashes, original launcher settings and Minecraft startup were verified.' }}</p>
            @endif
            @if($receiptReadable && (($context['running_original_sync_pending'] ?? false) === true))<p class="mt-2 text-sm text-amber-600">Original installation verified; Wings configuration release is pending. Use Retry verified recovery sync. No restore or restart is needed.</p>@endif
            @if($operation->phase === 'awaiting_manual_start')
                <p class="mt-3 text-sm">{{ ($context['native_manual_start']['role'] ?? '') === 'deploy' ? 'Updated files are in place.' : 'The original files have been restored.' }} Use Pelican to start Minecraft if it is stopped, then verify startup. Another update is blocked until this one is closed.</p>
            @endif
            @if($confirmation ?? null)
                <div role="status" class="mt-3 text-sm">
                    <p class="font-semibold">{{ match ($confirmation->state) {
                        'queued' => 'Confirmation queued',
                        'checking_server' => 'Checking running server',
                        'verifying_files' => 'Verifying deployed files',
                        'validating_configs' => 'Validating configuration changes',
                        'finalizing' => 'Completing update',
                        'completed' => 'Confirmation completed',
                        'blocked' => 'Confirmation blocked by another deployment or recovery writer',
                        'dispatch_pending' => 'Confirmation saved and waiting for queue dispatch',
                        default => 'Confirmation failed',
                    } }}</p>
                    <p>Confirmation <code>{{ $confirmation->id }}</code></p>
                    @if($confirmation->error)<p class="break-words">{{ $confirmation->error }}</p>@endif
                    @if(($canConfirmRunning ?? false) && (int) auth()->id() === $confirmation->actor_id && in_array($confirmation->state, ['blocked', 'dispatch_pending'], true))
                        <x-filament::button class="mt-2" color="gray" wire:click="retryConfirmation('{{ $confirmation->id }}')" wire:loading.attr="disabled">Retry confirmation</x-filament::button>
                    @endif
                </div>
            @endif
            @if(($canConfirmRunning ?? false) && ! ($confirmationPending ?? false))
                <div class="mt-4 flex flex-wrap items-center gap-2">
                    <x-filament::button color="primary" wire:click="reviewCurrentConfiguration('{{ $operation->id }}')" wire:loading.attr="disabled" wire:target="reviewCurrentConfiguration,acceptCurrentConfiguration">Review current configuration</x-filament::button>
                    <span wire:loading wire:target="reviewCurrentConfiguration" role="status" class="text-sm">Checking through Wings. This can take a while.</span>
                </div>
                @if(($configurationSetReview ?? null) && ($configurationSetReview['operation_id'] ?? null) === $operation->id)
                    <div class="mt-3 overflow-x-auto">
                        <p class="text-sm font-semibold">{{ count($configurationSetReview['differences']) }} differences for {{ $configurationSetReview['target_version'] }}</p>
                        <p class="mt-1 text-sm">Accept the listed configurations as local changes without rewriting files or restarting Minecraft.</p>
                        @if($configurationSetReview['runtime_error'] ?? null)<p>{{ $configurationSetReview['runtime_error'] }}</p>@endif
                        <table class="amm-review-table mt-2 text-left text-sm">
                            <thead><tr><th scope="col" class="p-2">File</th><th scope="col" class="p-2">Review</th></tr></thead>
                            <tbody>
                                @forelse($configurationSetReview['differences'] as $difference)
                                    <tr class="border-t border-gray-200 dark:border-gray-700">
                                        <td class="p-2 break-all">
                                            <details><summary class="cursor-pointer">{{ $difference['path'] }}</summary>
                                                <p class="mt-1 text-xs">Expected <code>{{ $difference['expected_sha256'] ?? '[absent]' }}</code></p>
                                                <p class="text-xs">Current <code>{{ $difference['actual_sha256'] ?? '[absent or unreadable]' }}</code></p>
                                            </details>
                                        </td>
                                        <td class="p-2">{{ $difference['reason'] ?? 'Can keep locally' }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="2" class="p-2">All checked files match.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                        @if($configurationSetReview['can_confirm'])
                            <x-filament::button class="mt-3" color="primary" wire:click="acceptCurrentConfiguration" wire:loading.attr="disabled" wire:target="reviewCurrentConfiguration,acceptCurrentConfiguration"
                                wire:confirm="I accept the displayed file paths and hashes as local configuration and confirm that I connected to the server running {{ $configurationSetReview['target_version'] }}. Finish without automatic startup verification?">Accept configuration and finish update</x-filament::button>
                        @else
                            <p class="text-amber-600">Nasazení nelze dokončit: striktní cesty a runtime musí souhlasit.</p>
                        @endif
                    </div>
                @endif
                <details class="mt-3 text-sm" @if(($deploymentReview ?? null) || ($configReview ?? null)) open @endif>
                    <summary class="cursor-pointer">Individual verification options</summary>
                    <div class="mt-2 flex flex-wrap gap-2">
                        <x-filament::button color="gray" wire:click="reviewRunningDeployment('{{ $operation->id }}')" wire:loading.attr="disabled">Review files individually</x-filament::button>
                        @if(($deploymentReview['can_confirm'] ?? false) && ($deploymentReview['operation_id'] ?? null) === $operation->id)
                            <x-filament::button color="gray" wire:click="confirmRunningDeployment('{{ $operation->id }}')" wire:loading.attr="disabled"
                            wire:confirm="I connected to the server and verified it is running {{ $context['to'] ?? '' }}. Continue without automatic startup verification? Unresolved file differences will block completion.">Confirm running server</x-filament::button>
                        @endif
                    </div>
                </details>
            @endif
            @if(($canConfirmRunning ?? false) && ! ($confirmationPending ?? false))
                @if(($deploymentReview ?? null) && ($deploymentReview['operation_id'] ?? null) === $operation->id)
                    <div class="mt-3 overflow-x-auto">
                        <p class="text-sm">Cílový manifest: <code>{{ $deploymentReview['target_manifest_sha256'] }}</code>. Přijetí mění pouze audit lokálního hashe, nikoli soubory nebo upstream baseline.</p>
                        @if($deploymentReview['runtime_error'] ?? null)<p class="text-sm text-amber-600">{{ $deploymentReview['runtime_error'] }}</p>@endif
                        <table class="mt-2 w-full text-left text-sm">
                            <thead><tr><th>Cesta</th><th>Ověřený cíl SHA-256</th><th>Aktuální SHA-256</th><th>Stav / akce</th></tr></thead>
                            <tbody>
                                @forelse(($deploymentReview['differences'] ?? []) as $difference)
                                    <tr>
                                        <td class="break-all">{{ $difference['path'] }}</td>
                                        <td class="break-all"><code>{{ $difference['expected_sha256'] ?? '[očekávána nepřítomnost]' }}</code></td>
                                        <td class="break-all"><code>{{ $difference['actual_sha256'] ?? '[nepřítomný nebo nečitelný]' }}</code></td>
                                        <td>
                                            @if($difference['accepted'])
                                                Přijato jako konkrétní lokální změna
                                            @elseif($difference['reviewable'])
                                                <x-filament::button color="gray" size="sm" wire:click="reviewDeploymentConfig(@js($operation->id), @js($difference['path']), @js($difference['actual_sha256']))">Ověřit text a zobrazit diff</x-filament::button>
                                            @else
                                                Striktní kontrola: {{ $difference['reason'] }}
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="4">Všechny nasazené cesty souhlasí.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                @endif
                @if(($configReview ?? null) && ($configReview['operation_id'] ?? null) === $operation->id)
                    <div class="mt-3">
                        <p class="font-medium break-all">{{ $configReview['path'] }}</p>
                        <p class="text-sm break-all">Ověřený cíl: {{ $configReview['expected_sha256'] }}. Aktuální lokální hash: {{ $configReview['actual_sha256'] }}.</p>
                        <pre class="mt-2 max-h-96 overflow-auto whitespace-pre text-xs">{{ $configReview['diff'] }}</pre>
                        <x-filament::button color="warning" wire:click="acceptDeploymentConfig" wire:loading.attr="disabled"
                            wire:confirm="Výslovně přijímám pouze {{ $configReview['path'] }} s aktuálním SHA-256 {{ $configReview['actual_sha256'] }} jako lokální změnu této operace a tohoto cílového manifestu. Živý config ani baseline se nepřepíší. Pokračovat?">Přijmout tuto cestu a hash jako lokální změnu</x-filament::button>
                    </div>
                @endif
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
            <details class="mt-3 text-sm">
                <summary class="cursor-pointer">Operation details</summary>
                <p class="mt-2 break-all">Operation: {{ $operation->id }} · Last report {{ $operation->heartbeat_at?->diffForHumans() ?? 'unavailable' }}</p>
                @if($context['backup_uuid'] ?? null)<p class="mt-2 break-all">Locked Pelican backup: {{ $context['backup_uuid'] }}</p>@endif
                @if($operation->phase === 'manual_intervention' && ($context['journal'] ?? null))<p class="mt-2 break-all">Retained deployment journal: {{ $context['journal'] }}</p>@endif
                @if($context['preserved_stash'] ?? null)<p class="mt-2 break-all">Preserved excluded folders: {{ $context['preserved_stash'] }}</p>@endif
            </details>
        </x-filament::section>
    @endif
</div>
