<?php
declare(strict_types=1);

namespace Arcturus\ModpackManager\Filament\Admin\Pages;

use Arcturus\ModpackManager\Jobs\CheckRelease;
use Arcturus\ModpackManager\Models\ManagedServer;
use Arcturus\ModpackManager\Models\UpdateEvent;
use Arcturus\ModpackManager\Services\PreviewService;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Collection;

final class Overview extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-arrow-path-rounded-square';
    protected static ?int $navigationSort = 1;
    protected static ?string $title = 'Modpack Manager';
    protected string $view = 'modpack-manager::overview';

    public static function getNavigationGroup(): ?string { return 'Modpack Manager'; }
    public static function getNavigationLabel(): string { return 'Overview'; }

    public static function canAccess(): bool
    {
        return auth()->check() && (bool) auth()->user()->isRootAdmin();
    }

    public function getServers(): Collection
    {
        return ManagedServer::query()->orderBy('label')->get();
    }

    public function getRecentEvents(): Collection
    {
        return UpdateEvent::query()->with('managedServer')
            ->latest()->limit(8)->get();
    }

    /** @return array{total:int,updates:int,errors:int,active:int} */
    public function getStats(): array
    {
        return [
            'total' => ManagedServer::count(),
            'active' => ManagedServer::where('enabled', true)->count(),
            'updates' => ManagedServer::query()->whereNotNull('available_version')
                ->whereColumn('available_version', '!=', 'installed_version')->count(),
            'errors' => ManagedServer::query()->whereNotNull('last_error')->count(),
        ];
    }

    public function checkAll(): void
    {
        foreach (ManagedServer::query()->where('enabled', true)->pluck('id') as $id) {
            CheckRelease::dispatch((int) $id);
        }
        Notification::make()->title('Release checks queued')
            ->body('Results will appear here after the queue worker processes them.')
            ->success()->send();
    }

    public function previewOne(int $serverId): void
    {
        $server = ManagedServer::findOrFail($serverId);
        try {
            $preview = app(PreviewService::class)->generate($server);
            Notification::make()->title('Dry-run complete')
                ->body($preview['blocked'] . ' unresolved file conflicts · ' . $preview['total'] . ' files compared')
                ->color($preview['blocked'] ? 'warning' : 'success')->send();
        } catch (\Throwable $error) {
            Notification::make()->title('Cannot build preview')
                ->body($error->getMessage())->danger()->send();
        }
    }

    public function checkOne(int $serverId): void
    {
        $server = ManagedServer::findOrFail($serverId);
        if (!$server->enabled) {
            Notification::make()->title('Enable this server first')->warning()->send();
            return;
        }
        CheckRelease::dispatch($server->id);
        Notification::make()->title("Checking {$server->label}")->success()->send();
    }
}
