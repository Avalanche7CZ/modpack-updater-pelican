<x-filament-panels::page>
    @php($state = $this->state())
    <div class="amm-dashboard space-y-4">
    @include('arcturus-modpack-manager::dashboard-styles')
    <x-filament::section heading="Managed modpack" icon="heroicon-o-cube">
        @if(!$state)
            <p>This server has not been adopted by Arcturus Modpack Manager.</p>
        @else
            <p class="mb-3 font-semibold">{{ $state['name'] }}</p>
            <dl class="amm-grid">
                <div><dt class="amm-label">Installed release</dt><dd class="amm-value">{{ $state['installed'] ?? 'Unknown' }}</dd></div>
                <div><dt class="amm-label">Latest checked release</dt><dd class="amm-value">{{ $state['available'] ?? '—' }}</dd></div>
                <div><dt class="amm-label">Last successful check</dt><dd>{{ $state['checked'] ?? 'Never' }}</dd></div>
            </dl>
            <details class="mt-4"><summary class="cursor-pointer">Installation details</summary>
                <dl class="amm-grid mt-3">
                    @foreach(['provider' => 'Provider', 'baseline' => 'Upstream baseline', 'comparison' => 'Installation comparison', 'policy' => 'Update policy', 'operation' => 'Last operation'] as $field => $label)
                        <div><dt class="amm-label">{{ $label }}</dt><dd class="break-all">{{ $state[$field] ?? 'Unavailable' }}</dd></div>
                    @endforeach
                </dl>
            </details>
            <details class="mt-2"><summary class="cursor-pointer">Protection rules (read only)</summary>
                @foreach($state['rules'] as $rule)<p class="break-all text-sm">{{ $rule['enabled'] ? 'Enabled' : 'Disabled' }} · {{ $rule['scope'] }}: {{ $rule['pattern'] }} · {{ \Arcturus\ModpackManager\Services\ProtectionPolicy::ACTIONS[$rule['mode']] }}</p>@endforeach
                <p class="text-sm">Disabled mods: {{ implode(', ', $state['disabled_mods']) ?: 'None specified' }}</p>
            </details>
            <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">Updates and runtime changes require administrator review.</p>
        @endif
    </x-filament::section>
    </div>
</x-filament-panels::page>
