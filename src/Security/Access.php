<?php

declare(strict_types=1);

namespace Arcturus\ModpackManager\Security;

use App\Enums\SubuserPermission;
use App\Models\Server;

final class Access
{
    public static function admin(): bool
    {
        return auth()->check() && (bool) auth()->user()->isRootAdmin();
    }

    public static function requireAdmin(): void
    {
        abort_unless(self::admin(), 403);
    }

    public static function view(?Server $server): bool
    {
        return $server !== null && auth()->check() && (self::admin() ||
            (int) $server->owner_id === (int) auth()->id() ||
            auth()->user()->can(SubuserPermission::FileRead, $server));
    }
}
