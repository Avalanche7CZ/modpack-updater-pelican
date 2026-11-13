<x-filament::widget>
@php($consoleServer = \Filament\Facades\Filament::getTenant())
@if($consoleServer instanceof \App\Models\Server && \Arcturus\ModpackManager\Security\Access::view($consoleServer) && \Arcturus\ModpackManager\ConsoleIntegration\ConsoleIntegration::managed($consoleServer))
    <section id="arcturus-modpack-output-{{ $consoleServer->uuid }}" class="rounded-lg border p-3 text-sm" aria-label="Modpack update">
        <strong>Modpack update</strong>
        <p data-status>Connecting to update history…</p>
        <ol data-lines aria-live="polite"></ol>
    </section>
    @script
    <script>
        (() => {
            const serverUuid = @js($consoleServer->uuid);
            const endpoint = @js(route('arcturus-modpack.console.lines', ['uuid' => $consoleServer->uuid]));
            {!! file_get_contents(plugin_path('arcturus-modpack-manager', 'resources/js/console-output.js')) !!}
        })();
    </script>
    @endscript
@endif
</x-filament::widget>
