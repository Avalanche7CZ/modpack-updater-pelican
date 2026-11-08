<x-filament-panels::page>
    <p class="mb-4 text-sm">This page configures the legacy local backend. Native Pelican / Wings requires no host volume permission setup.</p>
    <x-filament::section heading="Node readiness">
        <p class="font-medium">{{ ($this->report['ready'] ?? false) ? 'Ready for local adoption and baseline preparation' : 'Host setup required or readiness checks failed' }}</p>
        @foreach($this->report['checks'] ?? [] as $check)<p class="mt-2 break-words text-sm">{{ $check }}</p>@endforeach
        <p class="mt-3">New server default: {{ match (config('arcturus-modpack-manager.default_filesystem_backend', 'native')) { 'native' => 'Native Pelican / Wings', 'local' => 'Legacy local for the approved node', default => 'Legacy remote node worker' } }}.</p>
        <p class="text-sm">Checks read metadata only. This page never grants ACLs, executes sudo, changes live file contents or enables deployment.</p>
    </x-filament::section>
    <x-filament::section heading="One-time Ubuntu host setup">
        <p>On the machine running this Panel and the local Wings node, choose the correct node below. Verify its volume root is the real local Wings root. Run its commands once as an Ubuntu administrator. Replace www-data if PHP-FPM and pelican-queue use another unprivileged account.</p>
        <p class="mt-2 text-sm">The command approves one node, grants restricted read/traverse ACLs, creates a shared private staging parent and requests a graceful queue restart. No daemon, proxy, API token, per-server JSON or environment edit is needed. Append --dry-run to the setup command to preview the scope.</p>
        @foreach($this->setupCommands() as $node)
            <details class="mt-4"><summary class="cursor-pointer">{{ $node['name'] }} · Node {{ $node['id'] }}</summary>
                <pre class="mt-2 overflow-x-auto whitespace-pre-wrap break-all text-sm">{{ $node['command'] }}</pre>
            </details>
        @endforeach
        <a class="mt-3 inline-block text-primary-600 underline" href="https://github.com/Avalanche7CZ/modpack-updater-pelican/blob/main/docs/local-filesystem.md" target="_blank" rel="noopener noreferrer">Host setup scope, permissions and recovery instructions</a>
    </x-filament::section>
    <x-filament::section heading="Discovered servers on the approved local node">
        <p class="mb-3 text-sm">Only Pelican database server UUIDs assigned to the approved node are eligible. A private UUID staging directory is created on adoption; existing servers can select Filesystem backend → Same-host filesystem. Baselines still come from verified upstream files.</p>
        @forelse($this->report['servers'] ?? [] as $server)
            <div class="border-b border-gray-200 py-3 dark:border-gray-700"><p>{{ $server['name'] }}</p><p class="break-all text-sm">{{ $server['uuid'] }}</p><p class="text-sm">{{ $server['state'] }}</p></div>
        @empty<p>No eligible server metadata available. Complete setup and refresh readiness.</p>@endforelse
    </x-filament::section>
</x-filament-panels::page>
