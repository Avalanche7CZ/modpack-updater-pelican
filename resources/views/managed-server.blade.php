<x-filament-panels::page>
    @php($record = $this->managed())
    @php($preview = $record->preview_summary ?? [])
    @php($inventory = $record->file_inventory ?? [])
    @php($warnings = $this->inventoryWarnings())
    @php($runtimeObservation = array_replace($record->runtime_report ?? [], $preview['qualification']['current'] ?? []))
    @php($target = $record->target_metadata ?? $record->release_metadata ?? [])
    <div wire:poll.10s>
        <x-filament::tabs label="Modpack management">
            @foreach(['overview' => 'Overview', 'updates' => 'Updates', 'changes' => 'File Changes', 'mods' => 'Mods & Files', 'protection' => 'Protection Rules', 'backups' => 'Backups', 'history' => 'History'] as $key => $label)
                <x-filament::tabs.item :active="$this->tab === $key" wire:click="selectTab('{{ $key }}')">{{ $label }}</x-filament::tabs.item>
            @endforeach
        </x-filament::tabs>
        <div class="mt-4 space-y-4">
            @livewire(\Arcturus\ModpackManager\Livewire\DeploymentStatus::class, ['managedServerId' => $record->id], key('deployment-status-'.$record->id))
            @livewire(\Arcturus\ModpackManager\Livewire\PreparationStatus::class, ['managedServerId' => $record->id], key('preparation-status-'.$record->id))
            @if($record->active_operation_id && $record->operations()->whereKey($record->active_operation_id)->whereIn('kind', ['baseline', 'prepare', 'comparison'])->exists())
                <div class="flex flex-wrap gap-2">
                    <x-filament::button color="gray" wire:click="resumePreparation">Reconcile preparation</x-filament::button>
                    <x-filament::button color="warning" wire:click="cancelPreparation">Cancel preparation</x-filament::button>
                </div>
            @endif
            @if($inventory['unreadable_count'] ?? 0)
                @php($inventorySummary = \Arcturus\ModpackManager\Services\InventoryWarnings::summary($inventory))
                <x-filament::section heading="Protected unreadable content">
                    <p class="text-sm">{{ number_format($inventorySummary['files_hashed']) }} readable files hashed;
                        {{ number_format($inventorySummary['unreadable_files_skipped']) }} known files skipped,
                        {{ number_format($inventorySummary['unreadable_directories']) }} unreadable directories,
                        {{ number_format($inventorySummary['unreadable_entries_unknown']) }} other inaccessible entries.
                        Unreadable directory contents were not enumerated; their total file count is unknown.</p>
                    <p class="mt-2 text-sm">These paths and their descendants are protected from all update rules. Excluded content is not verified. No permissions were changed. Keep the exclusions, or ask the host operator to review access if you want these paths managed, then retry comparison using the existing baseline.</p>
                    @if($inventory['world_path_unreadable'] ?? false)
                        <p class="mt-2 text-sm">World path settings were unreadable. Every directory is protected because the custom world location cannot be determined.</p>
                    @endif
                    <ul class="mt-2 list-inside list-disc text-sm">
                        @foreach(\Arcturus\ModpackManager\Services\InventoryWarnings::unreadable($warnings) as $warning)
                            <li class="break-all">{{ $warning }}</li>
                        @endforeach
                    </ul>
                    @if($inventory['unreadable_count'] > 250)<p class="mt-2 text-sm">Showing the first 250 protected paths.</p>@endif
                </x-filament::section>
            @endif
            @if($inventory['symlink_count'] ?? 0)
                <x-filament::section heading="Preserved symlinks">
                    <p class="text-sm">These links and their descendants are excluded from updates. Their targets were not read. Comparison can complete without changing them.</p>
                    <ul class="mt-2 list-inside list-disc text-sm">
                        @foreach(\Arcturus\ModpackManager\Services\InventoryWarnings::symlinks($warnings) as $warning)
                            <li class="break-all">{{ $warning }}</li>
                        @endforeach
                    </ul>
                </x-filament::section>
            @endif
            @if($this->tab === 'overview')
                <x-filament::section heading="Existing installation">
                    <p class="mb-3 text-sm">Preparation / inventory backend: {{ $record->transport === 'local' ? 'Same-host local filesystem' : 'Remote node worker' }}.
                        <a class="text-primary-600 underline" href="https://github.com/Avalanche7CZ/modpack-updater-pelican/blob/main/docs/local-filesystem.md" target="_blank" rel="noopener noreferrer">Same-host setup instructions</a>. Complete Local filesystem setup once, then use Filesystem backend or the default on adoption.</p>
                    <dl class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach(['label' => 'Server', 'provider' => 'Provider', 'source' => 'Project', 'installed_version' => 'Installed release', 'available_version' => 'Available release', 'baseline_status' => 'Upstream baseline'] as $field => $label)
                            <div><dt class="text-sm text-gray-500 dark:text-gray-400">{{ $label }}</dt><dd class="break-all">{{ $record->$field ?? 'Unavailable' }}</dd></div>
                        @endforeach
                    </dl>
                    @if(($record->adoption_report['policy_revision'] ?? $record->policy_revision) !== $record->policy_revision)
                        <p class="mt-2 text-sm">Showing retained comparison evidence from an earlier policy revision. A new comparison is required.</p>
                    @endif
                    <p class="mt-4">{{ $record->comparisonLabel() }}.</p>
                    @if($record->baseline_status === 'verified' && $record->comparison_status !== 'complete')
                        <p class="text-sm">Upstream baseline verified. Installation comparison still needs to succeed. Review directory decisions and use Retry installation comparison; the existing manifest is reused.</p>
                    @endif
                    @if($record->comparison_diagnostic)
                        <p class="mt-2 text-sm">{{ \Arcturus\ModpackManager\Services\PreparationDiagnostic::message($record->comparison_diagnostic) }}</p>
                    @endif
                    @if(!$record->adopted_at)<p class="mt-4">Adoption draft. Prepare the installed baseline and review File Changes before finishing adoption.</p>@endif
                </x-filament::section>
                @if($this->nodeCapabilities)
                    <x-filament::section heading="Node capabilities">
                        @foreach($this->nodeCapabilities as $capability => $value)
                            <p class="break-words text-sm">{{ str_replace('_', ' ', $capability) }}: {{ is_bool($value) ? ($value ? 'Available' : 'Unavailable') : $value }}</p>
                        @endforeach
                    </x-filament::section>
                @endif
                <x-filament::section heading="Observed runtime">
                    <dl class="grid gap-4 sm:grid-cols-2">
                        @foreach(['state' => 'Last observed state', 'loader' => 'Loader', 'loader_version' => 'Loader version', 'minecraft' => 'Minecraft', 'java' => (($runtimeObservation['java_confidence'] ?? '') === 'verified' ? 'Java (startup verified)' : 'Java image hint'), 'image' => 'Docker image', 'egg' => 'Egg', 'startup' => 'Startup command', 'observed_at' => 'Observed at'] as $field => $label)
                            <div><dt class="text-sm text-gray-500 dark:text-gray-400">{{ $label }}</dt><dd class="break-all">{{ $runtimeObservation[$field] ?? 'Unknown' }}</dd></div>
                        @endforeach
                    </dl>
                </x-filament::section>
            @elseif($this->tab === 'updates')
                <x-filament::section heading="Update preview">
                    <p class="font-medium break-all">{{ $preview['from'] ?? $record->installed_version }} → {{ $preview['to'] ?? $record->targetVersion() ?? 'Select a release' }}</p>
                    @if($preview)
                        <dl class="mt-4 grid gap-3 sm:grid-cols-3">
                            @foreach(['add' => 'Added', 'update' => 'Updated', 'remove' => 'Removed', 'preserve_local_change' => 'Local changes kept', 'skip_disabled' => 'Disabled mods kept', 'conflict' => 'Conflicts'] as $action => $label)
                                <div><dt class="text-sm text-gray-500 dark:text-gray-400">{{ $label }}</dt><dd>{{ $preview['counts'][$action] ?? 0 }}</dd></div>
                            @endforeach
                        </dl>
                    @endif
                    @if($preview['qualification']['runtime_strategy'] ?? null)<p class="mt-3 text-sm">Runtime: reuse verified installed Forge {{ $preview['qualification']['current']['loader_version'] }} / Minecraft {{ $preview['qualification']['current']['minecraft'] }} / Java {{ $preview['qualification']['current']['java'] }}. No installer is run and working launch settings are preserved.</p>@endif
                    <p class="mt-4">A completed, locked Pelican backup is required before deployment. Runtime settings are preserved.</p>
                    <p class="text-sm text-gray-500 dark:text-gray-400">Estimated download: {{ isset($target['download_bytes']) ? number_format($target['download_bytes']) . ' bytes' : 'Unavailable' }}</p>
                    <ul class="mt-3 list-inside list-disc text-sm">
                        @foreach(array_unique(array_merge($target['blockers'] ?? [], $preview['capability_blockers'] ?? [], $record->transport !== 'local' ? ['Remote deployment remains disabled. Select and qualify an approved same-host backend.'] : [])) as $reason)
                            <li class="break-words">{{ $reason }}</li>
                        @endforeach
                    </ul>
                    @if(!$this->canApplyUpdate() && !$record->active_operation_id)<p class="mt-2 text-sm">Resolve the listed blockers and Compare files again. Qualified readiness expires after 15 minutes; each manual deployment needs a new exact-plan approval.</p>@endif
                    <x-filament::button color="danger" wire:click="mountAction('applyUpdate')" :disabled="!$this->canApplyUpdate()" class="mt-4">Apply Update{{ $this->canApplyUpdate() ? '' : ' — blocked' }}</x-filament::button>
                </x-filament::section>
                <x-filament::section heading="Release notes">
                    <div class="whitespace-pre-wrap break-words text-sm">{{ $target['notes'] ?? 'No release notes available.' }}</div>
                </x-filament::section>
            @elseif($this->tab === 'changes')
                @php($changes = $this->changeRows())
                @if($this->fileDiff)
                    <x-filament::section heading="Local / upstream text diff">
                        <p class="break-all">{{ $this->fileDiff['path'] }}</p>
                        <p class="text-sm">{{ $this->fileDiff['notice'] }}</p>
                        <pre class="mt-3 max-h-96 overflow-auto whitespace-pre-wrap break-all text-sm">{{ $this->fileDiff['content'] }}</pre>
                    </x-filament::section>
                @endif
                @if($this->mergeReview)
                    <x-filament::section heading="Configuration merge review">
                        <p class="break-all">{{ $this->mergeReview['path'] }}</p>
                        <p class="text-sm">{{ $this->mergeReview['notice'] }}</p>
                        @if($this->mergeReview['merged'])
                            <pre class="mt-3 overflow-x-auto whitespace-pre-wrap break-all text-sm">{{ $this->mergeReview['content'] }}</pre>
                        @else<p>Conflicting keys: {{ implode(', ', $this->mergeReview['conflicts']) }}</p>@endif
                    </x-filament::section>
                @endif
                <x-filament::section heading="File Changes">
                    <p class="mb-3 text-sm">KubeJS follows upstream when the local file matches the verified baseline. Local-only scripts and protected files stay local. Conflicts require Keep local or Use upstream; scripts, recipes and datapacks are never merged.</p>
                    @if(($preview['policy_revision'] ?? $record->policy_revision) !== $record->policy_revision)
                        <p class="mb-3 text-sm">Decisions saved. Showing previous comparison while a fresh comparison is required. No live files changed.</p>
                    @endif
                    <div class="flex flex-wrap gap-3">
                        <x-filament::input.wrapper><x-filament::input wire:model.live.debounce.400ms="pathSearch" placeholder="Filter file paths" aria-label="Filter file paths" /></x-filament::input.wrapper>
                        <x-filament::input.wrapper><x-filament::input.select wire:model.live="classification" aria-label="File classification">
                            <option value="">All classifications</option>
                            @foreach(['new_upstream', 'updated_upstream', 'removed_upstream', 'locally_modified', 'local_only', 'missing_or_removed_locally', 'protected', 'disabled_mod', 'merge_conflict', 'unsafe_or_ambiguous'] as $group)
                                <option value="{{ $group }}">{{ str_replace('_', ' ', ucfirst($group)) }}</option>
                            @endforeach
                        </x-filament::input.select></x-filament::input.wrapper>
                    </div>
                    <p class="my-3 text-sm text-gray-500 dark:text-gray-400">{{ $changes['total'] }} changed files · page {{ $changes['page'] }} / {{ $changes['pages'] }}. Worlds are excluded from hashing.</p>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm" style="min-width:48rem;table-layout:fixed"><colgroup><col style="width:40%"><col style="width:20%"><col style="width:15%"><col style="width:25%"></colgroup>
                            <thead><tr><th class="p-2">Path</th><th class="p-2">Classification</th><th class="p-2">Planned action</th><th class="p-2">Resolution</th></tr></thead>
                            <tbody>
                                @forelse($changes['rows'] as $change)
                                    <tr wire:key="change-{{ hash('sha256', $change['path']) }}" class="border-t border-gray-200 dark:border-gray-700">
                                        <td class="p-2"><x-filament::input.checkbox wire:model.live="selectedPaths" value="{{ $change['path'] }}" aria-label="Select {{ $change['path'] }}" /> <details><summary class="cursor-pointer break-all">{{ $change['path'] }}</summary><dl class="mt-2 break-all">
                                            @foreach(['base_sha256' => 'Upstream baseline', 'local_sha256' => 'Installed', 'target_sha256' => 'Target'] as $field => $label)<dt class="text-gray-500">{{ $label }}</dt><dd>{{ $change[$field] ?? 'Absent' }}</dd>@endforeach
                                            <dt class="text-gray-500">Effective rule</dt><dd>{{ $change['rule_origin'] ?? 'Three-way comparison' }} · {{ $change['rule'] ?? 'follow' }}</dd>
                                            <dt class="text-gray-500">Matching rule rows</dt><dd>{{ implode(', ', $change['matching_rules'] ?? []) ?: 'None' }}</dd>
                                            <dt class="text-gray-500">Approval / destructive</dt><dd>{{ ($change['approval_required'] ?? false) ? 'Approval required' : 'Plan review' }} · {{ ($change['destructive'] ?? false) ? 'Destructive' : 'Preserves live data' }}</dd>
                                        </dl></details></td>
                                        <td class="p-2">{{ str_replace('_', ' ', $change['classification']) }}</td>
                                        <td class="p-2">{{ str_replace('_', ' ', $change['action']) }}</td>
                                        <td class="p-2">
                                            @if(($change['blocked'] ?? false) && !$record->active_operation_id)
                                                <div class="flex flex-wrap gap-2">
                                                    @if(\Arcturus\ModpackManager\Services\KubeJsDiff::supports($change['path']))
                                                        <x-filament::button size="xs" color="gray" wire:loading.attr="disabled" wire:click="reviewFileDiff(@js($change['path']))">View text diff</x-filament::button>
                                                    @elseif(!str_starts_with($change['path'], 'kubejs/') && str_ends_with($change['path'], '.properties'))
                                                        <x-filament::button size="xs" color="gray" wire:click="reviewMerge(@js($change['path']))">Review text merge</x-filament::button>
                                                    @endif
                                                    <x-filament::button size="xs" color="gray" wire:loading.attr="disabled" wire:click="resolve(@js($change['path']), 'protect')">Keep local</x-filament::button>
                                                    <x-filament::button size="xs" color="warning" wire:confirm="Use upstream for this file, including removal if absent upstream? A future deployment still requires a reviewed plan and backup." wire:loading.attr="disabled" wire:click="resolve(@js($change['path']), 'replace')">Use upstream</x-filament::button>
                                                </div>
                                            @else — @endif
                                        </td>
                                    </tr>
                                @empty <tr><td colspan="4" class="p-3">{{ $preview || $record->adoption_report ? 'No matching changed files.' : 'Prepare the baseline or target, then compare files.' }}</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    <div class="mt-3 flex gap-2">
                        <x-filament::button color="gray" wire:click="$set('changePage', {{ max(1, $changes['page'] - 1) }})" :disabled="$changes['page'] <= 1">Previous</x-filament::button>
                        <x-filament::button color="gray" wire:click="$set('changePage', {{ min($changes['pages'], $changes['page'] + 1) }})" :disabled="$changes['page'] >= $changes['pages']">Next</x-filament::button>
                    </div>
                </x-filament::section>
            @elseif($this->tab === 'mods')
                @php($files = $this->fileRows())
                <x-filament::section heading="Mods & Files">
                    <div class="flex flex-wrap items-center gap-3">
                        <x-filament::input.wrapper><x-filament::input wire:model.live.debounce.400ms="pathSearch" placeholder="Search path, mod ID or name" aria-label="Search files and mods" /></x-filament::input.wrapper>
                        <x-filament::input.wrapper><x-filament::input.select wire:model.live="classification" aria-label="File state">
                            <option value="">All states</option>
                            @foreach(\Arcturus\ModpackManager\Services\ProtectionPolicy::CLASSIFICATIONS as $state)<option value="{{ $state }}">{{ str_replace('_', ' ', $state) }}</option>@endforeach
                        </x-filament::input.select></x-filament::input.wrapper>
                        <label class="flex items-center gap-2 text-sm"><x-filament::input.checkbox wire:model.live="modsOnly" /> Mods only</label>
                        <span class="text-sm">{{ count($this->selectedPaths) }} selected</span>
                    </div>
                    <p class="my-3 text-sm text-gray-500 dark:text-gray-400">{{ $files['total'] }} files · inventory observed {{ $inventory['observed_at'] ?? 'not yet' }}. Prepare the baseline or compare files to refresh. Unavailable metadata is never guessed.</p>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm" style="min-width:52rem;table-layout:fixed">
                            <thead><tr><th class="p-2">Select / installed file</th><th class="p-2">Mod metadata</th><th class="p-2">State / target</th><th class="p-2">Effective policy</th></tr></thead>
                            <tbody>
                            @forelse($files['rows'] as $file)
                                <tr wire:key="file-{{ hash('sha256', $file['path']) }}" class="border-t border-gray-200 dark:border-gray-700">
                                    <td class="p-2 break-all"><label><x-filament::input.checkbox wire:model.live="selectedPaths" value="{{ $file['path'] }}" /> {{ $file['path'] }}</label></td>
                                    <td class="p-2 break-all">{{ $file['name'] ?? 'Name unavailable' }}<br>{{ $file['identity'] ?? 'ID ambiguous / unavailable' }}<br>{{ $file['version'] ?? 'Version unavailable' }} · {{ $file['loader'] ?? 'Loader unknown' }}<p class="text-xs text-gray-500">{{ $file['identity_status'] }}</p></td>
                                    <td class="p-2 break-all">{{ str_replace('_', ' ', $file['classification']) }}{{ $file['disabled'] ? ' · disabled' : '' }}<br>{{ str_replace('_', ' ', $file['action']) }}
                                        @if($file['target_path'])<p>Target: {{ $file['target_path'] }} · {{ $file['target_version'] ?? 'Version unavailable' }}</p>@endif
                                        <p>{{ $file['base_sha256'] ? 'Upstream file' : ($file['classification'] === 'local_only' ? 'Manually installed' : ($file['classification'] === 'new_upstream' ? 'New upstream file' : 'Origin not compared')) }}</p>
                                    </td>
                                    <td class="p-2 break-all">{{ $file['invariant'] ?? \Arcturus\ModpackManager\Services\ProtectionPolicy::ACTIONS[$file['mode'] ?? 'follow'] }}<br>{{ $file['origin'] }}<p class="text-xs text-gray-500">Matching rules: {{ implode(', ', $file['matching_rules']) ?: 'None' }}</p></td>
                                </tr>
                            @empty<tr><td colspan="4" class="p-3">No matching inventory. Create and verify the upstream baseline, then compare the installation.</td></tr>@endforelse
                            </tbody>
                        </table>
                    </div>
                    <div class="mt-3 flex gap-2">
                        <x-filament::button color="gray" wire:click="$set('changePage', {{ max(1, $files['page'] - 1) }})" :disabled="$files['page'] <= 1">Previous</x-filament::button>
                        <x-filament::button color="gray" wire:click="$set('changePage', {{ min($files['pages'], $files['page'] + 1) }})" :disabled="$files['page'] >= $files['pages']">Next</x-filament::button>
                    </div>
                </x-filament::section>
            @elseif($this->tab === 'protection')
                <x-filament::section heading="Protection Rules">
                    <p class="mb-3 text-sm">Use Edit protection rules for templates, ordered rules and Test rule. Use Mods & Files to select real files and apply policies in bulk. Saving never modifies the installation.</p>
                    @forelse((new \Arcturus\ModpackManager\Services\ProtectionPolicy)->normalize($record->file_rules ?? []) as $rule)
                        <p class="break-all text-sm">#{{ $loop->iteration }} · {{ $rule['enabled'] ? 'Enabled' : 'Disabled' }} · {{ $rule['scope'] }}: <code>{{ $rule['pattern'] }}</code> · {{ \Arcturus\ModpackManager\Services\ProtectionPolicy::ACTIONS[$rule['mode']] }} · priority {{ $rule['priority'] }}{{ $rule['comment'] ? ' · '.$rule['comment'] : '' }}</p>
                    @empty<p>No additional rules. Three-way comparison still preserves unchanged upstream local customizations and blocks conflicts.</p>@endforelse
                    <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">World data and unsafe paths cannot be overridden. Exact paths take precedence over mod ID, filename, directory, glob, loader and extension rules. Higher priority and then later row break ties within a scope. Policy revision: {{ $record->policy_revision }}.</p>
                </x-filament::section>
            @elseif($this->tab === 'backups')
                <x-filament::section heading="Backup and recovery">
                    <dl class="space-y-3"><div><dt>Backend</dt><dd>{{ $record->backup_mode }}</dd></div><div><dt>Exact pre-update backup UUID</dt><dd class="break-all">{{ $record->last_backup_uuid ?? 'No update backup recorded' }}</dd></div><div><dt>Excluded directories</dt><dd>{{ implode(', ', $record->backup_excluded_directories ?? []) ?: 'None' }}</dd></div></dl>
                    <p class="mt-4 text-sm">Locked pre-update backups are retained. Truncating remote restores with excluded folders are blocked until outside-volume preservation is available. Do not restore with truncation without first protecting excluded data.</p>
                </x-filament::section>
            @elseif($this->tab === 'history')
                <x-filament::section heading="Recent job timeline">
                    @forelse($record->events()->select(['id', 'managed_server_id', 'kind', 'status', 'message', 'created_at'])->latest()->limit(100)->get() as $event)
                        <div class="border-b border-gray-200 py-3 dark:border-gray-700"><p class="text-sm text-gray-500">{{ $event->created_at }} · {{ $event->kind }} · {{ str_replace('_', ' ', $event->status) }}</p><p class="break-words">{{ $event->message }}</p></div>
                    @empty<p>No preparation events recorded. Check queue health if you expected a job.</p>@endforelse
                </x-filament::section>
            @endif
        </div>
    </div>
</x-filament-panels::page>
