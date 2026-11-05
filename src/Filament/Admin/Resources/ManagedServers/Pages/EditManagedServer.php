<?php

declare(strict_types=1);

namespace Arcturus\ModpackManager\Filament\Admin\Resources\ManagedServers\Pages;

use Arcturus\ModpackManager\Filament\Admin\Resources\ManagedServers\ManagedServerResource;
use Arcturus\ModpackManager\Security\Access;
use Arcturus\ModpackManager\Services\BackupExclusions;
use Arcturus\ModpackManager\Services\ModIdentityMappings;
use Arcturus\ModpackManager\Services\UpdatePlanner;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

final class EditManagedServer extends EditRecord
{
    protected static string $resource = ManagedServerResource::class;

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
