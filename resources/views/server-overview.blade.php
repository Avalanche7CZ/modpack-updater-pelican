<x-filament-panels::page>
    @php($state = $this->state())
    <x-filament::section heading="Managed modpack">
        @if(!$state)
            <p>This server has not been adopted by Arcturus Modpack Manager.</p>
        @else
            <dl class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach(['name' => 'Server', 'provider' => 'Provider', 'installed' => 'Installed release', 'available' => 'Available release', 'baseline' => 'Upstream baseline', 'comparison' => 'Installation comparison', 'checked' => 'Last successful check', 'policy' => 'Update policy', 'operation' => 'Last operation'] as $field => $label)
                    <div><dt class="text-sm text-gray-500 dark:text-gray-400">{{ $label }}</dt><dd class="break-all">{{ $state[$field] ?? 'Unavailable' }}</dd></div>
                @endforeach
            </dl>
            <details class="mt-4"><summary class="cursor-pointer">Protection rules (read only)</summary>
                @foreach($state['rules'] as $rule)<p class="break-all text-sm">{{ $rule['enabled'] ? 'Enabled' : 'Disabled' }} · {{ $rule['scope'] }}: {{ $rule['pattern'] }} · {{ \Arcturus\ModpackManager\Services\ProtectionPolicy::ACTIONS[$rule['mode']] }}</p>@endforeach
                <p class="text-sm">Disabled mods: {{ implode(', ', $state['disabled_mods']) ?: 'None specified' }}</p>
            </details>
            <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">Updates and runtime changes require administrator review.</p>
        @endif
    </x-filament::section>
</x-filament-panels::page>
