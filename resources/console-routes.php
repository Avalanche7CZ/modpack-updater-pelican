<?php

declare(strict_types=1);

use Arcturus\ModpackManager\ConsoleIntegration\ConsoleController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'auth.session', \App\Http\Middleware\RequireTwoFactorAuthentication::class, 'throttle:60,1'])->prefix('arcturus-modpack/console')->group(function (): void {
    Route::get('handoff/{managedServer}/diagnostics', \Arcturus\ModpackManager\Http\Controllers\HandoffDiagnosticsController::class)->whereNumber('managedServer')->name('arcturus-modpack.handoff.diagnostics');
    Route::get('{uuid}/lines', ConsoleController::class)->whereUuid('uuid')->name('arcturus-modpack.console.lines');
});
