<x-filament-panels::page>
    @php($record = $this->managed())
    @php($preview = $record->preview_summary ?? [])
    @php($inventory = $record->file_inventory ?? [])
    @php($warnings = $this->inventoryWarnings())
    @php($runtimeObservation = array_replace($record->runtime_report ?? [], $preview['qualification']['current'] ?? []))
    @php($target = $record->target_metadata ?? $record->release_metadata ?? [])
    <div wire:poll.10s="pollEvidence">
        <x-filament::tabs label="Modpack management">
            @foreach(['overview' => 'Overview', 'updates' => 'Updates', 'changes' => 'File Changes', 'mods' => 'Mods & Files', 'protection' => 'Protection Rules', 'backups' => 'Backups', 'history' => 'History'] as $key => $label)
                <x-filament::tabs.item :active="$this->tab === $key" wire:click="selectTab('{{ $key }}')">{{ $label }}</x-filament::tabs.item>
            @endforeach
        </x-filament::tabs>
        <div class="mt-4 space-y-4">
            @livewire(\Arcturus\ModpackManager\Livewire\DeploymentStatus::class, ['managedServerId' => $record->id], key('deployment-status-'.$record->id))
            @if(in_array($this->tab, ['overview', 'updates', 'changes'], true) || $record->active_operation_id)
                @livewire(\Arcturus\ModpackManager\Livewire\PreparationStatus::class, ['managedServerId' => $record->id, 'purpose' => $this->tab === 'updates' ? 'target' : 'all'], key('preparation-status-'.$record->id.'-'.$this->tab))
            @endif
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
                    <p class="mb-3 text-sm">Update backend: {{ match ($record->transport) { 'native' => 'Native Pelican / Wings', 'local' => 'Legacy same-host filesystem', default => 'Legacy remote node worker' } }}.
                        @if($record->transport === 'native')
                            Uses the existing Wings connection; no host volume permission setup is required.
                        @else
                            <a class="text-primary-600 underline" href="https://github.com/Avalanche7CZ/modpack-updater-pelican/blob/main/docs/native-deployment.md" target="_blank" rel="noopener noreferrer">Switch to Native Pelican / Wings</a> to avoid legacy host setup.
                        @endif
                    </p>
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
                <x-filament::section heading="Update modpack">
                    <ol class="mb-4 flex flex-wrap gap-3 text-sm" aria-label="Update steps">
                        <li>1. Select release</li><li>2. Prepare and review</li><li>3. Update with backup</li>
                    </ol>
                    <p class="font-medium break-all">{{ $record->installed_version }} → {{ $record->targetVersion() ?? 'Select a release' }}</p>
                    @if($record->active_operation_id)
                        <p class="mt-3 text-sm">An operation is running. Its progress appears above; wait for the result before continuing.</p>
                    @elseif($record->baseline_status !== 'verified')
                        <p class="mt-3 text-sm">First prepare the installed release in Overview to establish which files belong to the modpack.</p>
                    @elseif(!$record->targetVersion() || $record->targetVersion() === $record->installed_version)
                        <p class="mt-3 text-sm">Check releases, then select the version you want to install.</p>
                    @elseif(!$record->target_manifest_path)
                        <p class="mt-3 text-sm">Choose Prepare update. Download, verification and comparison run together in the background.</p>
                    @elseif(!$preview || ($preview['policy_revision'] ?? -1) !== $record->policy_revision || ($preview['to'] ?? null) !== $record->targetVersion())
                        <p class="mt-3 text-sm">The comparison needs refreshing. Open File Changes and choose Refresh comparison; the prepared pack is reused.</p>
                    @elseif($preview['blocked'] ?? 0)
                        <p class="mt-3 text-sm">Review the conflicts in File Changes before updating.</p>
                        <x-filament::button class="mt-3" color="warning" wire:click="selectTab('changes')">Review file changes</x-filament::button>
                    @elseif(!empty($preview['capability_blockers']))
                        <p class="mt-3 text-sm">File comparison is complete. Complete the deployment requirements listed below before updating.</p>
                        @php($pendingDirectories = (new \Arcturus\ModpackManager\Services\DirectoryPolicy)->pending($record))
                        @if($pendingDirectories)
                            <p class="mt-2 text-sm">New directories: {{ implode(', ', $pendingDirectories) }}. Existing decisions are retained.</p>
                            <x-filament::button class="mt-3" color="warning" wire:click="mountAction('directoryDecisions')">Review new directories</x-filament::button>
                        @endif
                    @else
                        <p class="mt-3 text-sm">Review the changes below, then update with a Pelican backup. Runtime checks and exact-plan approval still apply.</p>
                        <x-filament::button class="mt-3" color="gray" wire:click="selectTab('changes')">View file changes</x-filament::button>
                    @endif
                    @if($preview && ($preview['to'] ?? null) === $record->targetVersion() && ($preview['policy_revision'] ?? -1) === $record->policy_revision)
                        <dl class="mt-4 grid gap-3 sm:grid-cols-3">
                            @foreach(['upgrade_mod' => 'Mod upgrades', 'add' => 'Files added', 'update' => 'Files updated', 'remove' => 'Files removed', 'preserve_local_change' => 'Local changes kept', 'skip_disabled' => 'Disabled mods kept'] as $action => $label)
                                <div><dt class="text-sm text-gray-500 dark:text-gray-400">{{ $label }}</dt><dd>{{ $preview['counts'][$action] ?? 0 }}</dd></div>
                            @endforeach
                        </dl>
                        <p class="mt-3 text-sm font-medium">{{ $preview['blocked'] ?? 0 }} changes need a decision.</p>
                    @endif
                    @if($preview['qualification']['runtime_strategy'] ?? null)<p class="mt-3 text-sm">Runtime: {{ $preview['qualification']['current']['loader'] ?? 'unknown' }} {{ $preview['qualification']['current']['loader_version'] ?? '' }} → {{ $preview['qualification']['target']['loader'] ?? 'unknown' }} {{ $preview['qualification']['target']['loader_version'] ?? '' }} / Minecraft {{ $preview['qualification']['target']['minecraft'] ?? 'unknown' }} / Java {{ $preview['qualification']['target']['java'] ?? 'unknown' }}.</p>@endif
                    @php($runtimePlan = $preview['qualification']['runtime_plan'] ?? null)
                    @if($runtimePlan && (($runtimePlan['startup'] ?? '') !== ($preview['qualification']['current']['startup'] ?? '') || ($runtimePlan['image'] ?? '') !== ($preview['qualification']['current']['image'] ?? '')))
                        <details class="mt-3 text-sm"><summary class="cursor-pointer">Review startup and image changes</summary>
                            <p class="mt-2 break-all">Image: {{ $preview['qualification']['current']['image'] ?? 'unknown' }} → {{ $runtimePlan['image'] }}</p>
                            <p class="mt-2">Current startup</p><pre class="whitespace-pre-wrap break-all">{{ $preview['qualification']['current']['startup'] ?? 'unknown' }}</pre>
                            <p class="mt-2">Target startup</p><pre class="whitespace-pre-wrap break-all">{{ $runtimePlan['startup'] }}</pre>
                        </details>
                    @endif
                    <p class="mt-4">A completed, locked Pelican backup is required before deployment. Runtime settings change only when the approved runtime plan requires it.</p>
                    <p class="text-sm text-gray-500 dark:text-gray-400">Estimated download: {{ isset($target['download_bytes']) ? number_format($target['download_bytes']) . ' bytes' : 'Unavailable' }}</p>
                    <ul class="mt-3 list-inside list-disc text-sm">
                        @foreach(array_unique(array_merge($target['blockers'] ?? [], $preview['capability_blockers'] ?? [], ! in_array($record->transport, ['local', 'native'], true) ? ['Select Native Pelican / Wings and prepare the target to qualify deployment.'] : [])) as $reason)
                            <li class="break-words">{{ $reason }}</li>
                        @endforeach
                    </ul>
                    @if(!$this->canApplyUpdate() && !$record->active_operation_id)<p class="mt-2 text-sm">Complete the requirements above. Expired readiness is refreshed automatically in the background. Each manual update requires approval of the current file and runtime plan.</p>@endif
                    <x-filament::button color="danger" wire:click="mountAction('applyUpdate')" :disabled="!$this->canApplyUpdate()" class="mt-4">Update with backup</x-filament::button>
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
                    <div wire:key="file-decisions-{{ $record->policy_revision }}-{{ $record->comparison_status }}-{{ $record->active_operation_id ?? 'idle' }}" x-data="{ feedback: '', failed: false, comparisonPending: {{ $record->active_operation_id ? 'true' : 'false' }} }"
                        x-on:file-decision-result.window="feedback = $event.detail.message; failed = !$event.detail.success; if ($event.detail.success) comparisonPending = true">
                    <p x-cloak x-show="feedback" x-text="feedback" role="status" aria-live="polite" class="mb-3 break-words text-sm"></p>
                    <p wire:loading wire:target="resolve" role="status" class="mb-3 text-sm">Saving decision…</p>
                    @if($preview && ($preview['comparison_schema'] ?? null) !== \Arcturus\ModpackManager\Services\ManifestComparison::SCHEMA)<p class="mb-3 text-sm">This comparison uses the previous update engine. Use Refresh comparison to rebuild the manifest delta before applying an update.</p>@endif
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
                    <p class="my-3 text-sm text-gray-500 dark:text-gray-400">{{ $changes['total'] }} changes · page {{ $changes['page'] }} / {{ $changes['pages'] }}. Only update candidate paths are checked; worlds and unrelated files are not scanned.</p>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm" style="min-width:48rem;table-layout:fixed"><colgroup><col style="width:40%"><col style="width:20%"><col style="width:15%"><col style="width:25%"></colgroup>
                            <thead><tr><th class="p-2">File / mod upgrade</th><th class="p-2">Classification</th><th class="p-2">Planned action</th><th class="p-2">Resolution</th></tr></thead>
                            <tbody>
                                @forelse($changes['rows'] as $change)
                                    <tr wire:key="change-{{ hash('sha256', $change['path']) }}" class="border-t border-gray-200 dark:border-gray-700">
                                        <td class="p-2">
                                            @if($change['mod_upgrade'] ?? null)
                                                <strong class="break-all">{{ $change['identity'] }}</strong>
                                                <p class="break-all">Installed: {{ $change['mod_upgrade']['installed']['path'] ?? 'Absent or ambiguous' }} · {{ $change['mod_upgrade']['installed']['version'] ?? 'Version unavailable' }}</p>
                                                <p class="break-all">Target: {{ $change['mod_upgrade']['target']['path'] ?? 'Ambiguous' }} · {{ $change['mod_upgrade']['target']['version'] ?? 'Version unavailable' }}</p>
                                            @endif
                                            <x-filament::input.checkbox wire:model.live="selectedPaths" value="{{ $change['path'] }}" aria-label="Select {{ $change['path'] }}" /> <details><summary class="cursor-pointer break-all">{{ $change['path'] }}</summary>
                                            @if($change['mod_upgrade'] ?? null)
                                                <p class="mt-2 break-words">{{ $change['mod_upgrade']['reason'] }}</p>
                                                <p class="mt-2 break-all">Baseline: {{ $change['mod_upgrade']['baseline']['path'] ?? 'Ambiguous' }} · {{ $change['mod_upgrade']['baseline']['version'] ?? 'Version unavailable' }}</p>
                                                @foreach($change['mod_upgrade']['candidates'] as $scope => $candidates)
                                                    @if(count($candidates) > 1)<p class="break-all">{{ ucfirst($scope) }} candidates: {{ implode(', ', array_keys($candidates)) }}</p>@endif
                                                @endforeach
                                            @endif
                                            <dl class="mt-2 break-all">
                                            @foreach(['base_sha256' => 'Upstream baseline', 'local_sha256' => 'Installed', 'target_sha256' => 'Target'] as $field => $label)<dt class="text-gray-500">{{ $label }}</dt><dd>{{ $change[$field] ?? 'Absent' }}</dd>@endforeach
                                            <dt class="text-gray-500">Effective rule</dt><dd>{{ $change['rule_origin'] ?? 'Three-way comparison' }} · {{ $change['rule'] ?? 'follow' }}</dd>
                                            <dt class="text-gray-500">Matching rule rows</dt><dd>{{ implode(', ', $change['matching_rules'] ?? []) ?: 'None' }}</dd>
                                            <dt class="text-gray-500">Approval / destructive</dt><dd>{{ ($change['approval_required'] ?? false) ? 'Approval required' : 'Plan review' }} · {{ ($change['destructive'] ?? false) ? 'Destructive' : 'Preserves live data' }}</dd>
                                        </dl></details></td>
                                        <td class="p-2">{{ ($change['blocked'] ?? false) ? 'Needs review' : str_replace('_', ' ', $change['classification']) }}
                                            @if($change['blocked'] ?? false)
                                                <details class="mt-2"><summary class="cursor-pointer">Why?</summary>
                                                    <p class="mt-1">{{ str_replace('_', ' ', $change['classification']) }}</p>
                                                    @if(str_starts_with($change['action'], 'unsafe_'))<p>Review missing or conflicting mod identity evidence before choosing a replacement.</p>@endif
                                                </details>
                                            @endif
                                        </td>
                                        <td class="p-2">{{ ['upgrade_mod' => 'Upgrade mod', 'keep_mod' => 'Keep installed mod', 'keep_mod_disabled' => 'Keep disabled / absent', 'mod_upgrade_conflict' => 'Locally modified mod — choice required', 'mod_identity_review' => 'Identity review required', 'mod_upgrade_review' => 'Mod policy review required'][$change['action']] ?? str_replace('_', ' ', $change['action']) }}</td>
                                        <td class="p-2">
                                            @if(($change['blocked'] ?? false) && !$record->active_operation_id)
                                                <fieldset x-bind:disabled="comparisonPending" class="flex flex-wrap gap-2">
                                                    @php($decisionId = (string) \Illuminate\Support\Str::uuid())
                                                    @if(\Arcturus\ModpackManager\Services\KubeJsDiff::supports($change['path']))
                                                        <x-filament::button size="xs" color="gray" wire:loading.attr="disabled" :wire:click="'reviewFileDiff('.\Illuminate\Support\Js::from($change['path']).')'">View text diff</x-filament::button>
                                                    @elseif(!str_starts_with($change['path'], 'kubejs/') && str_ends_with($change['path'], '.properties'))
                                                        <x-filament::button size="xs" color="gray" :wire:click="'reviewMerge('.\Illuminate\Support\Js::from($change['path']).')'">Review text merge</x-filament::button>
                                                    @endif
                                                    <x-filament::button size="xs" color="gray" wire:loading.attr="disabled" :wire:click="'resolve('.\Illuminate\Support\Js::from($change['path']).', \'protect\', '.$record->policy_revision.', '.\Illuminate\Support\Js::from($decisionId).', '.$change['_evidence_index'].')'">Keep local</x-filament::button>
                                                    @if(!str_starts_with($change['action'], 'unsafe_') && (!isset($change['mod_upgrade']) || $change['mod_upgrade']['upstream_choice_allowed']))
                                                    <x-filament::button size="xs" color="warning" wire:confirm="Upgrade this entire mod operation or file to the verified upstream target, including removal of its previous JAR? No second active mod version will be added. A future deployment still requires a reviewed plan and backup." wire:loading.attr="disabled" :wire:click="'resolve('.\Illuminate\Support\Js::from($change['path']).', \'replace\', '.$record->policy_revision.', '.\Illuminate\Support\Js::from($decisionId).', '.$change['_evidence_index'].')'">Use upstream</x-filament::button>
                                                    @endif
                                                </fieldset>
                                            @else — @endif
                                        </td>
                                    </tr>
                                @empty <tr><td colspan="4" class="p-3">{{ $preview || $record->adoption_report ? 'No matching changed files.' : 'Prepare the baseline or target, then review the prepared changes.' }}</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    <div class="mt-3 flex gap-2">
                        <x-filament::button color="gray" wire:click="$set('changePage', {{ max(1, $changes['page'] - 1) }})" :disabled="$changes['page'] <= 1">Previous</x-filament::button>
                        <x-filament::button color="gray" wire:click="$set('changePage', {{ min($changes['pages'], $changes['page'] + 1) }})" :disabled="$changes['page'] >= $changes['pages']">Next</x-filament::button>
                    </div>
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
                    <p class="my-3 text-sm text-gray-500 dark:text-gray-400">{{ $files['total'] }} files / logical mod operations · inventory observed {{ $inventory['observed_at'] ?? 'not yet' }}. Prepare the baseline or compare files to refresh. Unavailable metadata is never guessed.</p>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm" style="min-width:52rem;table-layout:fixed">
                            <thead><tr><th class="p-2">Select / installed file</th><th class="p-2">Mod metadata</th><th class="p-2">State / target</th><th class="p-2">Effective policy</th></tr></thead>
                            <tbody>
                            @forelse($files['rows'] as $file)
                                <tr wire:key="file-{{ hash('sha256', $file['path']) }}" class="border-t border-gray-200 dark:border-gray-700">
                                    <td class="p-2 break-all"><label><x-filament::input.checkbox wire:model.live="selectedPaths" value="{{ $file['path'] }}" /> {{ $file['mod_upgrade']['installed']['path'] ?? $file['path'] }}</label></td>
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
                    <dl class="space-y-3"><div><dt>Backend</dt><dd>{{ $record->backup_mode }}</dd></div><div><dt>Exact pre-update backup UUID</dt><dd class="break-all">{{ $record->last_backup_uuid ?? 'A locked backup will be created when deployment starts' }}</dd></div><div><dt>Excluded directories</dt><dd>{{ implode(', ', $record->backup_excluded_directories ?? []) ?: 'None' }}</dd></div></dl>
                    @if($record->transport === 'native')
                        <p class="mt-4 text-sm">Recovery preserves excluded backup directories and the supported launcher link, verifies that the remaining root entries were cleared, then restores the exact locked backup without truncation. Unverified recovery keeps Minecraft stopped.</p>
                    @else
                        <p class="mt-4 text-sm">Locked pre-update backups are retained. Legacy restore requires its qualified exclusion-preservation procedure. Do not use a truncating restore without protecting excluded data.</p>
                    @endif
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
