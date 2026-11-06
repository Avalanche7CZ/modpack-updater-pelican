<?php

declare(strict_types=1);

namespace Arcturus\ModpackManager\Filament\Admin\Resources\ManagedServers\Pages;

use Arcturus\ModpackManager\Filament\Admin\Resources\ManagedServers\ManagedServerResource;
use Arcturus\ModpackManager\Security\Access;
use Arcturus\ModpackManager\Services\BackupExclusions;
use Arcturus\ModpackManager\Services\ModIdentityMappings;
use Arcturus\ModpackManager\Services\ProtectionPolicy;
use Arcturus\ModpackManager\Services\ServerPolicyService;
use Arcturus\ModpackManager\Services\UpdatePlanner;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Locked;

final class EditManagedServer extends EditRecord
{
    protected static string $resource = ManagedServerResource::class;

    #[Locked]
    public int $policyRevision = 0;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $this->policyRevision = $data['policy_revision'] ?? 0;
        $data['file_rules'] = (new ProtectionPolicy)->normalize($data['file_rules'] ?? []);

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $attributes = array_intersect_key($data, array_flip(['label', 'policy', 'enabled', 'asset_pattern', 'backup_mode', 'backup_excluded_directories', 'mod_identity_mappings']));
        app(ServerPolicyService::class)->save($record, $data['file_rules'] ?? [], $data['disabled_mods'] ?? [], $this->policyRevision, $attributes);
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

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
