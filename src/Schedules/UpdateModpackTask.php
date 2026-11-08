<?php

declare(strict_types=1);
namespace Arcturus\ModpackManager\Schedules;

use App\Extensions\Tasks\TaskSchemaInterface;
use App\Models\Schedule;
use App\Models\Task;
use Arcturus\ModpackManager\Models\ManagedServer;
use Arcturus\ModpackManager\Security\Access;
use Filament\Forms\Components\Select;

final class UpdateModpackTask implements TaskSchemaInterface
{
    public const ID = 'arcturus_modpack_update';
    public function getId(): string { return self::ID; }
    public function getName(): string { return 'Update Modpack'; }
    public function canCreate(Schedule $schedule): bool
    {
        return Access::admin() && ManagedServer::where('pelican_server_uuid', $schedule->server->uuid)->exists();
    }
    public function getDefaultPayload(): ?string { return 'update_restart'; }
    public function getPayloadLabel(): ?string { return 'When the installed release is current'; }
    public function formatPayload(string $payload): null|string|array { return $payload === 'update_restart' ? 'Update, or restart if current' : 'Update only'; }
    public function getPayloadForm(): array
    {
        return [Select::make('payload')->label($this->getPayloadLabel())->required()->default($this->getDefaultPayload())
            ->options(['update_restart' => 'Restart normally', 'update' => 'Leave the server running'])
            ->helperText('Enable scheduled deployment in the managed modpack settings first. A new release uses a Pelican backup and verified startup; following tasks wait for the result.')];
    }
    public function runTask(Task $task): void
    {
        // The bus handler keeps the native schedule open across preparation and deployment jobs.
        throw new \RuntimeException('Update Modpack requires the plugin schedule handler. Restart Panel queue workers after updating the plugin.');
    }
}
