<x-filament-panels::page>
    @php($stats = $this->getStats())
    <style>
        .mpm-surface{border:1px solid var(--gray-200,#e5e7eb);border-radius:14px;background:var(--fi-color-custom-50,transparent);overflow:hidden}
        .dark .mpm-surface{border-color:#374151}
        .mpm-grid{display:grid;gap:14px;grid-template-columns:repeat(4,minmax(0,1fr))}
        .mpm-stat{padding:18px;min-width:0}
        .mpm-stat small,.mpm-muted{color:#6b7280;font-size:.82rem}
        .dark .mpm-stat small,.dark .mpm-muted{color:#a1a1aa}
        .mpm-stat b{display:block;font-size:1.75rem;line-height:1.3;margin-top:6px;font-variant-numeric:tabular-nums}
        .mpm-title{font-size:1.08rem;font-weight:650}
        .mpm-toolbar{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:15px}
        .mpm-table{width:100%;border-collapse:collapse;text-align:left}
        .mpm-table th{font-size:.76rem;color:#6b7280;font-weight:600;letter-spacing:.02em}
        .mpm-table th,.mpm-table td{padding:14px 16px;vertical-align:middle;border-bottom:1px solid #e5e7eb}
        .dark .mpm-table th,.dark .mpm-table td{border-color:#374151}
        .mpm-table tr:last-child td{border-bottom:0}
        .mpm-table tbody tr:hover{background:rgba(125,125,125,.055)}
        .mpm-tag{display:inline-flex;border:1px solid #d4d4d8;border-radius:100px;padding:3px 9px;font-size:.75rem;white-space:nowrap}
        .dark .mpm-tag{border-color:#52525b}
        .mpm-tag-warn{color:#b45309;border-color:#fcd34d}
        .dark .mpm-tag-warn{color:#fbbf24;border-color:#854d0e}
        .mpm-tag-danger{color:#b91c1c;border-color:#fca5a5}
        .mpm-tag-good{color:#15803d;border-color:#86efac}
        .mpm-button{background:transparent;border:1px solid #d4d4d8;border-radius:9px;padding:7px 11px;font-size:.82rem;font-weight:550;cursor:pointer}
        .mpm-button:hover{background:rgba(120,120,120,.12)}
        .dark .mpm-button{border-color:#52525b}
        .mpm-link{color:#2563eb;text-decoration:none;font-weight:600}
        .dark .mpm-link{color:#93c5fd}
        .mpm-events{display:grid;grid-template-columns:minmax(0,2fr) minmax(240px,1fr);gap:14px}
        .mpm-empty{padding:44px 20px;text-align:center}
        .mpm-inline{display:flex;align-items:center;gap:9px;flex-wrap:wrap}
        @media(max-width:850px){.mpm-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.mpm-events{grid-template-columns:1fr}}
        @media(max-width:600px){.mpm-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.mpm-table th:nth-child(3),.mpm-table td:nth-child(3){display:none}}
    </style>

    <div class="mpm-toolbar">
        <div>
            <div class="mpm-title">Update overview</div>
            <div class="mpm-muted">Release monitoring across your connected Pelican servers</div>
        </div>
        <div class="mpm-inline">
            <x-filament::button color="gray" icon="heroicon-o-arrow-path" wire:click="checkAll" wire:loading.attr="disabled">
                Check releases
            </x-filament::button>
            <x-filament::button tag="a" icon="heroicon-o-plus" href="{{ \Arcturus\ModpackManager\Filament\Admin\Resources\ManagedServers\ManagedServerResource::getUrl('create') }}">
                Connect server
            </x-filament::button>
        </div>
    </div>

    <div class="mpm-grid">
        <div class="mpm-surface mpm-stat"><small>Managed servers</small><b>{{ $stats['total'] }}</b></div>
        <div class="mpm-surface mpm-stat"><small>Monitoring enabled</small><b>{{ $stats['active'] }}</b></div>
        <div class="mpm-surface mpm-stat"><small>Updates available</small><b>{{ $stats['updates'] }}</b></div>
        <div class="mpm-surface mpm-stat"><small>Check errors</small><b>{{ $stats['errors'] }}</b></div>
    </div>

    <div class="mpm-events">
        <div class="mpm-surface">
            <div style="padding:17px 16px 0" class="mpm-title">Servers</div>
            @if($this->getServers()->isEmpty())
                <div class="mpm-empty">
                    <div class="mpm-title">No servers connected</div>
                    <p class="mpm-muted">Connect an existing Pelican server to start watching for modpack releases.</p>
                </div>
            @else
                <div style="overflow-x:auto">
                    <table class="mpm-table">
                        <thead><tr><th>Server</th><th>Installed → latest</th><th>Policy</th><th>Action</th></tr></thead>
                        <tbody>
                        @foreach($this->getServers() as $server)
                            <tr wire:key="mpm-server-{{ $server->id }}">
                                <td>
                                    <a class="mpm-link" href="{{ \Arcturus\ModpackManager\Filament\Admin\Resources\ManagedServers\ManagedServerResource::getUrl('edit', ['record' => $server]) }}">
                                        {{ $server->label }}
                                    </a>
                                    <div class="mpm-muted">{{ strtoupper($server->provider) }} · {{ $server->source }}</div>
                                </td>
                                <td>
                                    <div class="mpm-inline">
                                        <code>{{ $server->installed_version }}</code>
                                        <span class="mpm-muted">→</span>
                                        <span class="mpm-tag {{ $server->last_error ? 'mpm-tag-danger' : ($server->hasUpdate() ? 'mpm-tag-warn' : '') }}">
                                            {{ $server->last_error ? 'Check failed' : ($server->available_version ?? 'Not checked') }}
                                        </span>
                                    </div>
                                    <div class="mpm-muted">{{ $server->last_checked_at?->diffForHumans() ?? 'Never checked' }}</div>
                                </td>
                                <td><span class="mpm-tag">{{ ucfirst($server->policy) }}</span></td>
                                <td>
                                    <div class="mpm-inline">
                                        <button class="mpm-button" type="button" wire:click="checkOne({{ $server->id }})" wire:loading.attr="disabled">
                                            Check
                                        </button>
                                        <button class="mpm-button" type="button" wire:click="previewOne({{ $server->id }})" wire:loading.attr="disabled">
                                            Preview
                                        </button>
                                        @if($server->hasUpdate())
                                            <button class="mpm-button" type="button"
                                                wire:click="prepareOne({{ $server->id }})" wire:confirm="Prepare the release in staging? The live server will not be modified."
                                                wire:loading.attr="disabled">Prepare</button>
                                        @endif
                                        @if($server->deploy_enabled && config('modpack-manager.enable_deploy', false) && $server->prepared_root && $server->target_manifest_path)
                                            <button class="mpm-button" type="button"
                                                wire:click="deployOne({{ $server->id }})"
                                                wire:confirm="This will stop the Minecraft server, update files and restart it. Continue?"
                                                wire:loading.attr="disabled">Apply</button>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                            @if($server->preview_summary)
                                <tr wire:key="mpm-preview-{{ $server->id }}">
                                    <td colspan="4" style="padding-top:0">
                                        <details>
                                            <summary class="mpm-muted" style="cursor:pointer">
                                                Last dry-run: {{ $server->preview_summary['from'] ?? '?' }}
                                                → {{ $server->preview_summary['to'] ?? '?' }} ·
                                                {{ $server->preview_summary['blocked'] ?? 0 }} unresolved ·
                                                {{ $server->last_preview_at?->diffForHumans() }}
                                            </summary>
                                            <div style="padding:12px 0;max-height:320px;overflow:auto">
                                                <div class="mpm-inline" style="margin-bottom:10px">
                                                @foreach(($server->preview_summary['counts'] ?? []) as $action => $count)
                                                    <span class="mpm-tag">{{ $action }}: {{ $count }}</span>
                                                @endforeach
                                                </div>
                                                <table class="mpm-table">
                                                    <thead><tr><th>File path</th><th>Planned action</th></tr></thead>
                                                    <tbody>
                                                    @foreach(($server->preview_summary['sample'] ?? []) as $change)
                                                        <tr><td><code style="word-break:break-all">{{ $change['path'] ?? '' }}</code></td>
                                                            <td><span class="mpm-tag {{ !empty($change['blocked']) ? 'mpm-tag-danger' : '' }}">{{ $change['action'] ?? '' }}</span></td></tr>
                                                    @endforeach
                                                    </tbody>
                                                </table>
                                            </div>
                                        </details>
                                    </td>
                                </tr>
                            @endif
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        <div class="mpm-surface">
            <div style="padding:17px 16px 0" class="mpm-title">Recent activity</div>
            <div style="padding:12px 16px">
                @forelse($this->getRecentEvents() as $event)
                    <div style="padding:10px 0;border-bottom:1px solid rgba(125,125,125,.15)">
                        <div class="mpm-inline">
                            <strong style="font-size:.88rem">{{ $event->managedServer?->label ?? 'Deleted server' }}</strong>
                            <span class="mpm-tag {{ $event->status === 'failed' ? 'mpm-tag-danger' : '' }}">{{ $event->kind }}</span>
                        </div>
                        <div class="mpm-muted" style="margin-top:5px">{{ $event->message }}</div>
                        <div class="mpm-muted">{{ $event->created_at->diffForHumans() }}</div>
                    </div>
                @empty
                    <p class="mpm-muted" style="padding:18px 0">No release checks recorded yet.</p>
                @endforelse
            </div>
        </div>
    </div>

    <div class="mpm-muted" style="margin-top:6px">
        Monitoring is read-only. File updates require a verified node worker, backup and explicit approval.
        A detected release is not proof that its server pack is compatible with this installation.
    </div>
</x-filament-panels::page>
