<?php

declare(strict_types=1);

namespace Arcturus\ModpackManager\Filament\Admin\Resources\ManagedServers\Pages;

use App\Models\Server;
use Arcturus\ModpackManager\Filament\Admin\Resources\ManagedServers\ManagedServerResource;
use Arcturus\ModpackManager\Filament\Forms\PolicyFields;
use Arcturus\ModpackManager\Security\Access;
use Arcturus\ModpackManager\Services\AdoptionService;
use Arcturus\ModpackManager\Services\BackupExclusions;
use Arcturus\ModpackManager\Services\ProtectionPolicy;
use Arcturus\ModpackManager\Services\UpdatePlanner;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Resources\Pages\CreateRecord\Concerns\HasWizard;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\View;
use Filament\Schemas\Components\Wizard\Step;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Locked;

final class CreateManagedServer extends CreateRecord
{
    use HasWizard { getWizardComponent as protected baseWizardComponent; }

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
                    'Wings: '.($this->inspection['state'] ?? 'unavailable'),
                    $this->inspection['loader'] ? 'Observed loader: '.$this->inspection['loader'] : 'Loader not verified',
                    $this->inspection['minecraft'] ? 'Minecraft: '.$this->inspection['minecraft'] : 'Minecraft version not verified',
                    ! empty($this->inspection['warnings']) ? 'Warnings: '.count($this->inspection['warnings']) : null,
                ])) : 'Inspection reads configuration through Wings. Adoption never stops or restarts the server.'),
                Text::make(fn () => $this->inspection ? implode(' · ', array_filter([
                    'Panel launch method: '.($this->inspection['launch_method'] ?? 'unknown'),
                    'Java image: '.mb_strimwidth((string) ($this->inspection['image'] ?? '(not available)'), 0, 100, '…'),
                    'Egg: '.mb_strimwidth((string) ($this->inspection['egg'] ?? '(not available)'), 0, 80, '…'),
                    $this->inspection['diagnostic'] ? 'Wings diagnostic: '.$this->inspection['diagnostic']['stage'].' / '.$this->inspection['diagnostic']['code'] : null,
                ])) : ''),
                Text::make(fn () => $this->inspection && ! empty($this->inspection['warnings'])
                    ? 'Inspection warning: '.$this->inspection['warnings'][0] : ''),
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
                Select::make('backup_mode')->label('Backup backend')->options(['pelican' => 'Pelican Backups', 'filesystem' => 'Legacy filesystem snapshot (local only)'])->default('pelican')->required(),
                ...PolicyFields::rules(true),
                Text::make('World data and path safety remain protected. Rules are saved for this server only. Automatic deployment stays disabled.'),
            ]),
            Step::make('Verify and review')->schema([
                Radio::make('adoption_mode')->options(['draft' => 'Create a draft and prepare the baseline', 'monitor' => 'Monitoring only — baseline unverified'])->default('draft')->required()->live(),
                View::make('arcturus-modpack-manager::adoption-review')->viewData(fn ($get) => $this->reviewData($get)),
            ]),
        ];
    }

    public function getWizardComponent(): Component
    {
        // Wizard expects rendered Htmlable content. An unregistered Action can render empty.
        return $this->baseWizardComponent()->submitAction(view('arcturus-modpack-manager::adoption-submit'));
    }

    public function reviewData($get): array
    {
        $rules = $get('file_rules') ?? [];

        return ['summary' => [
            'Server' => Server::where('uuid', $get('pelican_server_uuid'))->value('name') ?? 'Select a server',
            'Managed name' => $get('label') ?? '', 'Provider / project' => ($get('provider') ?? '').' / '.($get('source') ?? ''),
            'Installed release' => $get('installed_version') ?? '', 'Backup backend' => $get('backup_mode') ?? 'pelican',
            'Backup exclusions' => implode(', ', $get('backup_excluded_directories') ?? []) ?: 'None',
            'Protection rules' => count($rules).' server-specific rules',
            'Disabled mods' => implode(', ', $get('disabled_mods') ?? []) ?: 'None specified',
        ], 'rules' => $rules, 'warnings' => array_merge(['Automatic deployment remains disabled. A live inventory is an observation, never an upstream baseline.'], $this->inspection['warnings'] ?? [])];
    }

    public function inspectSelection(string $uuid): void
    {
        Access::requireAdmin();
        $server = Server::where('uuid', $uuid)->firstOrFail();
        try {
            $this->inspection = app(AdoptionService::class)->inspect($server);
        } catch (\Throwable $error) {
            report($error);
            $this->inspection = [
                'state' => 'unavailable', 'inspection_complete' => false,
                'warnings' => ['Runtime inspection failed. Check the Pelican Laravel log and Wings connectivity.'],
            ];
            Notification::make()
                ->title('Runtime inspection unavailable')
                ->body('Could not read server metadata through Wings. You can still create a monitoring-only draft.')
                ->danger()->send();

            return;
        }
        if (! ($this->inspection['inspection_complete'] ?? false) || ! empty($this->inspection['warnings'])) {
            $diagnostic = $this->inspection['diagnostic'] ?? null;
            Notification::make()
                ->title('Runtime inspection incomplete')
                ->body($diagnostic
                    ? 'Wings file listing: '.$diagnostic['code'].'. Panel startup and image are shown below; the loader is not verified. Check the Pelican/Wings logs.'
                    : 'Some launcher evidence or Wings state could not be read. Runtime compatibility remains unverified.')
                ->warning()->send();

            return;
        }
        Notification::make()->title('Read-only runtime inspection complete')->success()->send();
    }

    protected function handleRecordCreation(array $data): Model
    {
        Access::requireAdmin();
        $server = Server::where('uuid', $data['pelican_server_uuid'])->firstOrFail();
        try {
            $runtime = app(AdoptionService::class)->inspect($server);
        } catch (\Throwable $error) {
            // An unavailable Wings node must not prevent a database-only monitoring draft.
            $runtime = ['warnings' => ['Runtime inspection unavailable; inspect again when Wings is reachable.']];
        }
        $mode = $data['adoption_mode'] ?? 'draft';
        unset($data['adoption_mode'], $data['policy_template']);
        $data['file_rules'] = (new ProtectionPolicy)->normalize($data['file_rules'] ?? []);
        (new UpdatePlanner)->plan([], [], [], $data['file_rules'], [], $data['disabled_mods'] ?? []);
        if (! empty($runtime['protected_roots'])) {
            foreach ($data['backup_excluded_directories'] ?? [] as $folder) {
                if (in_array($folder, $runtime['protected_roots'], true)) {
                    throw new \InvalidArgumentException('World folders cannot be excluded from backups');
                }
            }
        }
        $data['backup_excluded_directories'] = BackupExclusions::normalize($data['backup_excluded_directories'] ?? []);

        $record = $this->getModel()::create([...$data, 'transport' => 'wings', 'baseline_status' => 'unverified',
            'runtime_report' => $runtime, 'enabled' => false, 'deploy_enabled' => false, 'policy' => config('arcturus-modpack-manager.default_policy', 'approve'), 'backup_mode' => $data['backup_mode'] ?? 'pelican']);
        if ($mode === 'monitor') {
            app(AdoptionService::class)->finish($record, true);
        }

        return $record;
    }

    protected function getRedirectUrl(): string
    {
        return self::getResource()::getUrl('view', ['record' => $this->record]);
    }
}
