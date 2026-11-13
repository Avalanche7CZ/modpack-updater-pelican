<?php

declare(strict_types=1);

namespace Arcturus\ModpackManager\ConsoleIntegration;

use App\Enums\SubuserPermission;
use App\Models\Server;
use Arcturus\ModpackManager\Security\Access;
use Illuminate\Http\Request;

final class ConsoleController
{
    public function __invoke(Request $request, string $uuid)
    {
        $server = Server::where('uuid', $uuid)->firstOrFail();
        abort_unless(Access::view($server) && auth()->user()->can(SubuserPermission::WebsocketConnect, $server), 403);
        $managed = ConsoleIntegration::managed($server);
        abort_unless($managed, 404);
        $data = $request->validate(['after' => ['nullable', 'integer', 'min:0', 'max:9007199254740991']]);

        return response()->json(ConsoleHistory::read($managed->id, isset($data['after']) ? (int) $data['after'] : null))
            ->header('Cache-Control', 'private, no-store');
    }
}
