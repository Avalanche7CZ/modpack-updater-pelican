<?php

declare(strict_types=1);

namespace Arcturus\ModpackManager\Filament\Admin\Resources\ManagedServers\Pages;

use App\Models\Server;
use Arcturus\ModpackManager\Filament\Admin\Resources\ManagedServers\ManagedServerResource;
use Arcturus\ModpackManager\Security\Access;
use Arcturus\ModpackManager\Services\AdoptionService;
use Arcturus\ModpackManager\Services\BackupExclusions;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Resources\Pages\CreateRecord\Concerns\HasWizard;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Wizard\Step;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Locked;

final class CreateManagedServer extends CreateRecord
{
    use HasWizard;

    protected static string $resource = ManagedServerResource::class;

    protected static ?string $title = 'Adopt Existing Server';

    #[Locked]
    public array $inspection = [];

    public function getSteps(): array
    {
        return [
            Step::make('Existing server')->schema([
                Select::make('pelican_server_uuid')->label('Pelican server')->required()->searchable()
                    ->options(fn () => Server::orderBy('name')->pluck('name', 'uuid'))->unique(ignoreRecord: true),
                TextInput::make('label')->label('Managed server name')->required()->maxLength(120),
                Actions::make([Action::make('inspect')->button()->icon('heroicon-o-magnifying-glass')->label('Inspect runtime and launch method')
                    ->action(fn ($get) => $this->inspectSelection((string) $get('pelican_server_uuid')))]),
                Text::make(fn () => $this->inspection ? implode(' · ', array_filter([
                    $this->inspection['state'] ?? null, $this->inspection['loader'] ?? 'Loader unknown',
                    $this->inspection['minecraft'] ?? 'Minecraft unknown', $this->inspection['pack_hint'] ?? null,
                ])) : 'Inspection reads configuration through Wings. Adoption never stops or restarts the server.'),
            ]),
            Step::make('Exact upstream release')->schema([
                Select::make('provider')->options(['ftb' => 'FTB', 'github' => 'GitHub / GTNH',
                    'modrinth' => 'Modrinth', 'curseforge' => 'CurseForge', 'atlauncher' => 'ATLauncher', 'custom' => 'Custom recipe'])
                    ->required()->default('github'),
                TextInput::make('source')->label('Project ID, owner/repository or approved recipe')->required()->maxLength(255),
                TextInput::make('installed_version')->label('Exact installed release ID / tag')->required()->maxLength(120)
                    ->helperText('FTB and CurseForge use numeric file/version IDs. Modrinth uses its immutable version ID. GitHub uses the tag.'),
                TextInput::make('asset_pattern')->label('Explicit server ZIP asset pattern')->maxLength(160)
                    ->helperText('GTNH: select the server asset matching the existing Java/Crucible variant.'),
            ]),
            Step::make('Protection and backup')->schema([
                TagsInput::make('backup_excluded_directories')->label('Excluded top-level backup folders')
                    ->default(fn () => config('arcturus-modpack-manager.default_backup_exclusions', ['backup', 'backups'])),
                Text::make('Worlds, identity files, Arcturus/Paradigm settings and launch scripts stay protected. Pelican Backups remains the default backend.'),
            ]),
            Step::make('Verify and review')->schema([
                Text::make('Create the adoption draft, then prepare the exact upstream baseline and review the file comparison. No files are installed. If the release cannot be verified, finish adoption for monitoring only.'),
            ]),
        ];
    }

    public function inspectSelection(string $uuid): void
    {
        Access::requireAdmin();
        $this->inspection = app(AdoptionService::class)->inspect(Server::where('uuid', $uuid)->firstOrFail());
        Notification::make()->title('Read-only runtime inspection complete')->success()->send();
    }

    protected function handleRecordCreation(array $data): Model
    {
        Access::requireAdmin();
        $runtime = app(AdoptionService::class)->inspect(Server::where('uuid', $data['pelican_server_uuid'])->firstOrFail());
        $data['backup_excluded_directories'] = BackupExclusions::normalize($data['backup_excluded_directories'] ?? []);

        return $this->getModel()::create([...$data, 'transport' => 'wings', 'baseline_status' => 'unverified',
            'runtime_report' => $runtime, 'enabled' => false, 'deploy_enabled' => false, 'policy' => config('arcturus-modpack-manager.default_policy', 'approve'), 'backup_mode' => 'pelican']);
    }

    protected function getRedirectUrl(): string
    {
        return self::getResource()::getUrl('view', ['record' => $this->record]);
    }
}
