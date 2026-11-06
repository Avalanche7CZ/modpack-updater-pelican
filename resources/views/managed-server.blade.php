<x-filament-panels::page>
    @php($record = $this->managed())
    @php($preview = $record->preview_summary ?? [])
    @php($target = $record->target_metadata ?? $record->release_metadata ?? [])
    <div wire:poll.10s>
        <x-filament::tabs label="Modpack management">
            @foreach(['overview' => 'Overview', 'updates' => 'Updates', 'changes' => 'File Changes', 'mods' => 'Mods & Files', 'protection' => 'Protection Rules', 'backups' => 'Backups', 'history' => 'History'] as $key => $label)
                <x-filament::tabs.item :active="$this->tab === $key" wire:click="selectTab('{{ $key }}')">{{ $label }}</x-filament::tabs.item>
            @endforeach
        </x-filament::tabs>
        <div class="mt-4 space-y-4">
            @livewire(\Arcturus\ModpackManager\Livewire\PreparationStatus::class, ['managedServerId' => $record->id], key('preparation-status-'.$record->id))
            @if($record->active_operation_id)
                <div class="flex flex-wrap gap-2">
                    <x-filament::button color="gray" wire:click="resumePreparation">Reconcile preparation</x-filament::button>
                    <x-filament::button color="warning" wire:click="cancelPreparation">Cancel preparation</x-filament::button>
                </div>
            @endif
            @if($this->tab === 'overview')
                <x-filament::section heading="Existing installation">
                    <p class="mb-3 text-sm">Preparation / inventory backend: {{ $record->transport === 'local' ? 'Same-host local filesystem' : 'Remote node worker' }}.
                        <a class="text-primary-600 underline" href="https://github.com/Avalanche7CZ/modpack-updater-pelican/blob/main/docs/local-filesystem.md" target="_blank" rel="noopener noreferrer">Same-host setup instructions</a>. Complete Local filesystem setup once, then use Filesystem backend or the default on adoption.</p>
                    <dl class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach(['label' => 'Server', 'provider' => 'Provider', 'source' => 'Project', 'installed_version' => 'Installed release', 'available_version' => 'Available release', 'baseline_status' => 'Baseline'] as $field => $label)
                            <div><dt class="text-sm text-gray-500 dark:text-gray-400">{{ $label }}</dt><dd class="break-all">{{ $record->$field ?? 'Unavailable' }}</dd></div>
                        @endforeach
                    </dl>
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
                        @foreach(['state' => 'Last observed state', 'loader' => 'Loader', 'loader_version' => 'Loader version', 'minecraft' => 'Minecraft', 'java' => 'Java image hint', 'image' => 'Docker image', 'egg' => 'Egg', 'startup' => 'Startup command', 'observed_at' => 'Observed at'] as $field => $label)
                            <div><dt class="text-sm text-gray-500 dark:text-gray-400">{{ $label }}</dt><dd class="break-all">{{ $record->runtime_report[$field] ?? 'Unknown' }}</dd></div>
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
                    <p class="mt-4">A completed, locked Pelican backup is required before deployment. Runtime settings are preserved.</p>
                    <p class="text-sm text-gray-500 dark:text-gray-400">Estimated download: {{ isset($target['download_bytes']) ? number_format($target['download_bytes']) . ' bytes' : 'Unavailable' }}</p>
                    <ul class="mt-3 list-inside list-disc text-sm">
                        @foreach(array_unique(array_merge($target['blockers'] ?? [], $preview['capability_blockers'] ?? [], ['Remote deployment is disabled pending transaction and restore integration qualification.'])) as $reason)
                            <li class="break-words">{{ $reason }}</li>
                        @endforeach
                    </ul>
                    <x-filament::button color="danger" disabled class="mt-4">Apply Update — blocked</x-filament::button>
                </x-filament::section>
                <x-filament::section heading="Release notes">
                    <div class="whitespace-pre-wrap break-words text-sm">{{ $target['notes'] ?? 'No release notes available.' }}</div>
                </x-filament::section>
            @elseif($this->tab === 'changes')
                @php($changes = $this->changeRows())
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
                                            @if(($change['blocked'] ?? false) && $preview && !$record->active_operation_id)
                                                <div class="flex flex-wrap gap-2">
                                                    @if(str_ends_with($change['path'], '.properties'))
                                                        <x-filament::button size="xs" color="gray" wire:click="reviewMerge(@js($change['path']))">Review text merge</x-filament::button>
                                                    @endif
                                                    <x-filament::button size="xs" color="gray" wire:click="resolve(@js($change['path']), 'protect')">Keep local</x-filament::button>
                                                    <x-filament::button size="xs" color="warning" wire:confirm="Approve an upstream replacement policy for this file? The next deployment still needs a reviewed plan and backup." wire:click="resolve(@js($change['path']), 'replace')">Accept upstream</x-filament::button>
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
                    <p class="my-3 text-sm text-gray-500 dark:text-gray-400">{{ $files['total'] }} files · inventory observed {{ $record->file_inventory['observed_at'] ?? 'not yet' }}. Prepare the baseline or compare files to refresh. Unavailable metadata is never guessed.</p>
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
                    @forelse($record->events()->latest()->limit(100)->get() as $event)
                        <div class="border-b border-gray-200 py-3 dark:border-gray-700"><p class="text-sm text-gray-500">{{ $event->created_at }} · {{ $event->kind }} · {{ str_replace('_', ' ', $event->status) }}</p><p class="break-words">{{ $event->message }}</p></div>
                    @empty<p>No preparation events recorded. Check queue health if you expected a job.</p>@endforelse
                </x-filament::section>
            @endif
        </div>
    </div>
</x-filament-panels::page>
