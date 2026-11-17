<x-filament::widget hidden style="display: none" data-modpack-console-widget>
@php($consoleServer = \Filament\Facades\Filament::getTenant())
@if($consoleServer instanceof \App\Models\Server && \Arcturus\ModpackManager\ConsoleIntegration\ConsoleOutput::canView())
    <div id="arcturus-modpack-output-{{ $consoleServer->uuid }}">
        <x-filament::section compact>
            <div class="flex items-start gap-3">
                <x-filament::icon icon="heroicon-o-arrow-path" class="h-5 w-5 shrink-0 text-primary-500" />
                <div class="min-w-0">
                    <h2 class="text-sm font-semibold">Modpack update</h2>
                    <p data-status role="status" class="break-words text-sm text-gray-600 dark:text-gray-300"></p>
                    <details data-history hidden class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                        <summary class="cursor-pointer">Recent steps</summary>
                        <ol data-lines class="mt-2 space-y-1"></ol>
                    </details>
                </div>
            </div>
        </x-filament::section>
    </div>
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
