<x-filament-panels::page>
    @php($state = $this->state())
    <x-filament::section heading="Managed modpack">
        @if(!$state)
            <p>This server has not been adopted by Arcturus Modpack Manager.</p>
        @else
            <dl class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach(['name' => 'Server', 'provider' => 'Provider', 'installed' => 'Installed release', 'available' => 'Available release', 'baseline' => 'Upstream baseline', 'checked' => 'Last successful check', 'policy' => 'Update policy', 'operation' => 'Last operation'] as $field => $label)
                    <div><dt class="text-sm text-gray-500 dark:text-gray-400">{{ $label }}</dt><dd class="break-all">{{ $state[$field] ?? 'Unavailable' }}</dd></div>
                @endforeach
            </dl>
            <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">Updates and runtime changes require administrator review.</p>
        @endif
    </x-filament::section>
</x-filament-panels::page>
