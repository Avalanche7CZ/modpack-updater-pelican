<?php

declare(strict_types=1);

namespace Arcturus\ModpackManager\Modpacks;

use Arcturus\ModpackManager\Contracts\ModpackProvider;
use Arcturus\ModpackManager\Modpacks\Providers\ATLauncherProvider;
use Arcturus\ModpackManager\Modpacks\Providers\CurseForgeProvider;
use Arcturus\ModpackManager\Modpacks\Providers\CustomProvider;
use Arcturus\ModpackManager\Modpacks\Providers\FtbProvider;
use Arcturus\ModpackManager\Modpacks\Providers\GitHubProvider;
use Arcturus\ModpackManager\Modpacks\Providers\GtnhProvider;
use Arcturus\ModpackManager\Modpacks\Providers\ModrinthProvider;
use InvalidArgumentException;

final class ProviderRegistry
{
    public const PROVIDERS = ['github' => GitHubProvider::class, 'gtnh' => GtnhProvider::class, 'modrinth' => ModrinthProvider::class,
        'ftb' => FtbProvider::class, 'curseforge' => CurseForgeProvider::class,
        'atlauncher' => ATLauncherProvider::class, 'custom' => CustomProvider::class];

    public function get(string $id): ModpackProvider
    {
        return app(self::PROVIDERS[$id] ?? throw new InvalidArgumentException('Unsupported provider'));
    }
}
