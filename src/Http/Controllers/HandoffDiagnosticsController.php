<?php

declare(strict_types=1);
namespace Arcturus\ModpackManager\Http\Controllers;

use Arcturus\ModpackManager\Models\ManagedServer;
use Arcturus\ModpackManager\Native\HandoffDiagnostics;
use Arcturus\ModpackManager\Security\Access;

final class HandoffDiagnosticsController
{
    public function __invoke(int $managedServer)
    {
        Access::requireAdmin();
        return response()->json(HandoffDiagnostics::report(ManagedServer::lightweight()->findOrFail($managedServer)))->header('Cache-Control', 'private, no-store');
    }
}
