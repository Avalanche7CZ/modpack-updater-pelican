<x-filament-panels::page>
    <div wire:poll.15s.visible class="amm-dashboard space-y-4">
        @include('arcturus-modpack-manager::dashboard-styles')
        @php($summary = $this->fleetSummary())
        <div class="amm-grid">
            @foreach(['servers' => 'Managed servers', 'updates' => 'Updates available', 'active' => 'Open operations'] as $metric => $label)
                <x-filament::section compact class="amm-status-card">
                    <dl>
                        <dt class="amm-label">{{ $label }}</dt>
                        <dd class="amm-value mt-1">{{ number_format($summary[$metric] ?? 0) }}</dd>
                    </dl>
                </x-filament::section>
            @endforeach
        </div>
        {{ $this->table }}
    </div>
</x-filament-panels::page>
