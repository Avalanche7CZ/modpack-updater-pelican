<?php

declare(strict_types=1);

namespace Arcturus\ModpackManager\Filament\Forms;

use Arcturus\ModpackManager\Models\DiscordDelivery;
use Arcturus\ModpackManager\Models\ManagedServer;
use Arcturus\ModpackManager\Security\Access;
use Arcturus\ModpackManager\Services\DiscordNotifier;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;

final class DiscordFields
{
    public static function webhook(string $label): TextInput
    {
        return TextInput::make('discord_webhook')->label($label)->password()->revealable()->copyable()
            ->autocomplete('off')->maxLength(512)->visible(fn () => Access::admin());
    }

    public static function section(): Section
    {
        return Section::make('Discord Notifications')->visible(fn () => Access::admin())->columns(2)->schema([
            Toggle::make('discord_enabled')->label('Enable notifications for this server')->default(true),
            TextInput::make('discord_role_id')->label('Role ID to ping (optional)')->maxLength(20)->placeholder('123456789012345678'),
            self::webhook('Server webhook override')
                ->helperText(fn ($record) => ($record?->discord_webhook ? 'Saved server override configured.' :
                    (config('arcturus-modpack-manager.discord_webhook') ? 'Global fallback configured; no server override.' : 'No server override or global fallback configured.'))
                    .' Show/Hide and Copy are available to administrators. Blank retains the saved override.'),
            Toggle::make('clear_discord_webhook')->label('Remove override and use global webhook')->default(false),
            Select::make('discord_notify_events')->label('Notify on')->multiple()->options(DiscordNotifier::EVENTS)
                ->default(DiscordNotifier::DEFAULT_EVENTS)->afterStateHydrated(function (Select $component, $state): void {
                    if ($state === null) {
                        $component->state(DiscordNotifier::DEFAULT_EVENTS);
                    }
                })->columnSpanFull(),
            Actions::make([Action::make('testDiscord')->label('Send Test Notification')->button()->color('gray')
                ->visible(fn ($record) => Access::admin() && $record instanceof ManagedServer && $record->exists)
                ->requiresConfirmation()->modalDescription('Tests the webhook currently entered, without saving. Blank retains the saved override; Remove override tests the global fallback. No roles are pinged.')
                ->action(function (ManagedServer $record, $get): void {
                    Access::requireAdmin();
                    try {
                        $delivery = app(DiscordNotifier::class)->test($record->refresh(), (string) $get('discord_webhook'), (bool) $get('clear_discord_webhook'));
                        Notification::make()->title('Test #'.$delivery->id.': Queued')
                            ->body('Delivery is not yet confirmed. The status below updates automatically.')->info()->send();
                    } catch (\Throwable $error) {
                        Notification::make()->title('Test notification failed')
                            ->body(DiscordNotifier::diagnostic($error))->danger()->send();
                    }
                })])->columnSpanFull(),
            Text::make(function ($record): string {
                Access::requireAdmin();
                $delivery = $record ? DiscordDelivery::where('managed_server_id', $record->id)->where('kind', 'test')->latest('id')->first() : null;

                return app(DiscordNotifier::class)->testStatus($delivery);
            })->extraAttributes(['wire:poll.3s' => '$refresh', 'role' => 'status'])->columnSpanFull(),
        ]);
    }
}
