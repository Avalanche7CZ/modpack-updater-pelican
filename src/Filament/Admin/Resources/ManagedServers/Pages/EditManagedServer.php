<?php

declare(strict_types=1);

namespace Arcturus\ModpackManager\Filament\Admin\Resources\ManagedServers\Pages;

use Arcturus\ModpackManager\Filament\Admin\Resources\ManagedServers\ManagedServerResource;
use Arcturus\ModpackManager\Security\Access;
use Arcturus\ModpackManager\Services\BackupExclusions;
use Arcturus\ModpackManager\Services\DiscordSettings;
use Arcturus\ModpackManager\Services\ModIdentityMappings;
use Arcturus\ModpackManager\Services\ProtectionPolicy;
use Arcturus\ModpackManager\Services\ServerPolicyService;
use Arcturus\ModpackManager\Services\UpdatePlanner;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Locked;

final class EditManagedServer extends EditRecord
{
    protected static string $resource = ManagedServerResource::class;

    public static bool $formActionsAreSticky = true;

    #[Locked]
    public int $policyRevision = 0;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $this->policyRevision = $data['policy_revision'] ?? 0;
        Access::requireAdmin();
        $data['discord_webhook'] = $this->record->discord_webhook;
        $data['clear_discord_webhook'] = false;
        $data['file_rules'] = (new ProtectionPolicy)->normalize($data['file_rules'] ?? []);

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $attributes = array_intersect_key($data, array_flip(['label', 'policy', 'enabled', 'asset_pattern', 'backup_mode', 'backup_excluded_directories', 'mod_identity_mappings', 'pelican_server_uuid', 'provider', 'source', 'installed_version', 'transport']));
        $attributes += array_intersect_key($data, array_flip(DiscordSettings::FIELDS));
        try {
            app(ServerPolicyService::class)->save($record, $data['file_rules'] ?? [], $data['disabled_mods'] ?? [], $this->policyRevision, $attributes);
        } catch (\RuntimeException $error) {
            throw \Illuminate\Validation\ValidationException::withMessages(['data.transport' => $error->getMessage()]);
        }
        $record->refresh();
        $this->policyRevision = $record->policy_revision;

        return $record;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        Access::requireAdmin();
        $data['backup_excluded_directories'] = BackupExclusions::normalize($data['backup_excluded_directories'] ?? []);
        (new ModIdentityMappings)->apply([], $data['mod_identity_mappings'] ?? [], []);
        $rules = $data['file_rules'] ?? [];
        (new UpdatePlanner)->plan([], [], [], $rules, [], $data['disabled_mods'] ?? []);
        $data['approved_plan_sha256'] = null;
        $data['approved_at'] = null;
        $data['preview_summary'] = null;

        return $data;
    }

    protected function afterSave(): void
    {
        $this->data['discord_webhook'] = $this->record->discord_webhook;
        $this->data['clear_discord_webhook'] = false;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('saveChanges')->label('Save changes')->icon('heroicon-o-check')
                ->action(fn () => $this->save())->keyBindings(['mod+s']),
            $this->getCancelFormAction()->label('Cancel'),
            DeleteAction::make(),
        ];
    }
}
