<?php

declare(strict_types=1);

namespace Arcturus\ModpackManager;

use App\Contracts\Plugins\HasPluginSettings;
use Arcturus\ModpackManager\Filament\Forms\DiscordFields;
use Arcturus\ModpackManager\Security\Access;
use Arcturus\ModpackManager\Services\PluginSettings;
use Filament\Contracts\Plugin;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Panel;
use Filament\Schemas\Components\Section;

final class ModpackManagerPlugin implements HasPluginSettings, Plugin
{
    public static function make(): static
    {
        return new self;
    }

    public function getId(): string
    {
        return 'arcturus-modpack-manager';
    }

    public function register(Panel $panel): void
    {
        if (! in_array($panel->getId(), ['admin', 'server'], true)) {
            return;
        }
        $id = str($panel->getId())->title();
        $panel->discoverPages(
            plugin_path($this->getId(), "src/Filament/$id/Pages"),
            "Arcturus\\ModpackManager\\Filament\\$id\\Pages"
        );
        if ($panel->getId() === 'server') {
            \Arcturus\ModpackManager\ConsoleIntegration\ConsoleIntegration::register($panel);
        }
        if ($panel->getId() !== 'admin') {
            return;
        }
        $panel->discoverResources(
            plugin_path($this->getId(), "src/Filament/$id/Resources"),
            "Arcturus\\ModpackManager\\Filament\\$id\\Resources"
        );
    }

    public function getSettingsFormData(): array
    {
        return app(PluginSettings::class)->formData();
    }

    public function getSettingsForm(): array
    {
        Access::requireAdmin();

        return [
            Section::make('Provider and notifications')->schema([
                TextInput::make('curseforge_api_key')->password()->autocomplete('off')
                    ->helperText('Leave blank to keep the encrypted credential. Credentials are never loaded into this form.'),
                Toggle::make('clear_curseforge_api_key')->label('Remove saved CurseForge credential'),
                DiscordFields::webhook('Global fallback webhook')
                    ->helperText(fn () => config('arcturus-modpack-manager.discord_webhook') ? 'Global fallback is configured. Server overrides take priority.' : 'No global fallback configured.'),
                Toggle::make('clear_discord_webhook')->label('Remove saved Discord webhook'),
                Toggle::make('discord_events')->label('Enable Discord notifications globally'),
            ]),
            Section::make('Monitoring and staging')->schema([
                Select::make('default_policy')->options(['notify' => 'Notify only', 'approve' => 'Administrator approval'])->required(),
                TextInput::make('check_interval_hours')->numeric()->minValue(1)->maxValue(24)->required(),
                TagsInput::make('default_backup_exclusions')->label('Default excluded backup folders'),
                TextInput::make('max_archive_bytes')->label('Maximum stage bytes (also capped by the node)')->numeric()->required(),
                Select::make('download_concurrency')->options([1 => 'One verified transfer per server'])->required(),
            ]),
            Section::make('Safety policy')->description(
                'Runtime changes require administrator review. Deployments require a verified baseline and backup. '
                .'Node endpoints and per-server secrets are configured in the operator-owned node configuration. '
                .'Same-host updates use Pelican backups and restore on failure. Automatic maintenance requires explicit per-server opt-in; remote deployment remains disabled.'),
        ];
    }

    public function saveSettings(array $data): void
    {
        app(PluginSettings::class)->save($data);
    }

    public function boot(Panel $panel): void {}
}
