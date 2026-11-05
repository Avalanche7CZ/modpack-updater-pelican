<?php

declare(strict_types=1);

namespace Arcturus\ModpackManager;

use Filament\Contracts\Plugin;
use Filament\Panel;

final class ModpackManagerPlugin implements Plugin
{
    public static function make(): static
    {
        return new static();
    }

    public function getId(): string
    {
        return 'modpack-manager';
    }

    public function register(Panel $panel): void
    {
        // No navigation or server actions until a compatible admin UI is implemented.
    }

    public function boot(Panel $panel): void
    {
    }
}
