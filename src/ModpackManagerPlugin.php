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
        if ($panel->getId() !== 'admin') {
            return;
        }
        $id = str($panel->getId())->title();
        $panel->discoverPages(
            plugin_path($this->getId(), "src/Filament/$id/Pages"),
            "Arcturus\\ModpackManager\\Filament\\$id\\Pages"
        );
        $panel->discoverResources(
            plugin_path($this->getId(), "src/Filament/$id/Resources"),
            "Arcturus\\ModpackManager\\Filament\\$id\\Resources"
        );
    }

    public function boot(Panel $panel): void
    {
    }
}
