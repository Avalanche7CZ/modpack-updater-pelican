<?php

declare(strict_types=1);

namespace Arcturus\ModpackManager\Filament\Forms;

use Arcturus\ModpackManager\Modpacks\Providers\GtnhProvider;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Illuminate\Validation\ValidationException;

final class ReleaseSourceFields
{
    public const PROVIDERS = ['gtnh' => 'GTNH · Official downloads', 'ftb' => 'FTB', 'github' => 'GitHub Releases',
        'modrinth' => 'Modrinth', 'curseforge' => 'CurseForge', 'atlauncher' => 'ATLauncher', 'custom' => 'Custom recipe'];

    public static function fields(): array
    {
        return [
            Select::make('provider')->label('Provider')->options(self::PROVIDERS)->default('github')->required()->native(false)->live(),
            TextInput::make('source')->label('Project ID, owner/repository or approved recipe')->required()->maxLength(255)
                ->visible(fn ($get) => $get('provider') !== 'gtnh')
                ->helperText('FTB/CurseForge: numeric ID · GitHub: owner/repo · Modrinth: slug · Custom: verified recipe path or URL'),
            Select::make('gtnh_variant')->label('GTNH server ZIP variant')->options(GtnhProvider::VARIANTS)->required()->native(false)
                ->visible(fn ($get) => $get('provider') === 'gtnh')
                ->helperText('Choose the variant your server was installed from. Java 21 and 25 use Java 17+.'),
            TextInput::make('installed_version')->label(fn ($get) => $get('provider') === 'gtnh' ? 'Exact installed GTNH version' : 'Exact installed release ID / tag')
                ->required()->maxLength(120)
                ->helperText(fn ($get) => $get('provider') === 'gtnh' ? 'Enter the version currently installed, for example 2.8.1.'
                    : 'FTB and CurseForge use numeric IDs. Modrinth uses its immutable version ID. GitHub uses the tag.'),
            TextInput::make('asset_pattern')->label('Explicit server ZIP asset pattern')->maxLength(160)
                ->visible(fn ($get) => $get('provider') === 'github')
                ->helperText('Example: *Server*Java17*.zip — one matching GitHub release asset is required.'),
        ];
    }

    public static function normalize(array $data): array
    {
        if (($data['provider'] ?? null) === 'gtnh') {
            $variant = $data['gtnh_variant'] ?? null;
            if (! is_string($variant) || ! isset(GtnhProvider::VARIANTS[$variant])) {
                throw ValidationException::withMessages(['data.gtnh_variant' => 'Select the GTNH Java variant that matches the installed server.']);
            }
            $data['source'] = GtnhProvider::SOURCE;
            $data['asset_pattern'] = $variant;
        } elseif (isset($data['provider']) && $data['provider'] !== 'github') {
            $data['asset_pattern'] = null;
        }
        unset($data['gtnh_variant']);

        return $data;
    }
}
