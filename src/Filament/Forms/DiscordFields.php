<?php

declare(strict_types=1);

namespace Arcturus\ModpackManager\Filament\Forms;

use Arcturus\ModpackManager\Models\ManagedServer;
use Arcturus\ModpackManager\Services\DiscordNotifier;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;

final class DiscordFields
{
    public static function section(): Section
    {
        return Section::make('Discord Notifications')->columns(2)->schema([
            Toggle::make('discord_enabled')->label('Enable notifications for this server')->default(true),
            TextInput::make('discord_role_id')->label('Role ID to ping (optional)')->maxLength(20)->placeholder('123456789012345678'),
            TextInput::make('discord_webhook')->label('Server webhook override')->password()->autocomplete('off')->maxLength(512)
                ->afterStateHydrated(fn (TextInput $component) => $component->state(''))
                ->helperText('Leave blank to retain the saved override. Without an override, the global webhook is used. Saved tokens are never displayed.'),
            Toggle::make('clear_discord_webhook')->label('Remove override and use global webhook')->default(false),
            Select::make('discord_notify_events')->label('Notify on')->multiple()->options(DiscordNotifier::EVENTS)
                ->default(DiscordNotifier::DEFAULT_EVENTS)->afterStateHydrated(function (Select $component, $state): void {
                    if ($state === null) {
                        $component->state(DiscordNotifier::DEFAULT_EVENTS);
                    }
                })->columnSpanFull(),
            Actions::make([Action::make('testDiscord')->label('Send Test Notification')->button()->color('gray')
                ->visible(fn ($record) => $record instanceof ManagedServer && $record->exists)
                ->requiresConfirmation()->modalDescription('Uses saved notification settings. Sends one test embed without pinging roles or updating the Minecraft server.')
                ->action(function (ManagedServer $record): void {
                    try {
                        $delivery = app(DiscordNotifier::class)->test($record->refresh());
                        Notification::make()->title('Test notification queued')->body($delivery->last_error ?? 'Check History for delivery confirmation. Tests never ping roles.')->info()->send();
                    } catch (\Throwable) {
                        Notification::make()->title('Test notification unavailable')->body('Save a valid server or global webhook and check notification history.')->danger()->send();
                    }
                })])->columnSpanFull(),
        ]);
    }
}
