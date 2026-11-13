@php($consoleServer = \Filament\Facades\Filament::getTenant())
@if($consoleServer instanceof \App\Models\Server && \Arcturus\ModpackManager\Security\Access::view($consoleServer) && \Arcturus\ModpackManager\ConsoleIntegration\ConsoleIntegration::managed($consoleServer))
    <script>
        (() => {
            const serverUuid = @js($consoleServer->uuid);
            const endpoint = @js(route('arcturus-modpack.console.lines', ['uuid' => $consoleServer->uuid]));
            {!! file_get_contents(plugin_path('arcturus-modpack-manager', 'resources/js/console-output.js')) !!}
        })();
    </script>
@endif
