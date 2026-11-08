<?php

declare(strict_types=1);

namespace Arcturus\ModpackManager\Filament\Admin\Pages;

use App\Models\Node;
use Arcturus\ModpackManager\Local\LocalNode;
use Arcturus\ModpackManager\Security\Access;
use Arcturus\ModpackManager\Services\PluginSettings;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Livewire\Attributes\Locked;

final class LocalFilesystem extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-folder-open';
    protected static ?string $title = 'Local filesystem setup';
    protected static ?string $slug = 'arcturus-local-filesystem';
    protected static ?int $navigationSort = 3;
    protected string $view = 'arcturus-modpack-manager::local-filesystem';

    #[Locked]
    public array $report = [];

    public static function getNavigationGroup(): ?string
    {
        return 'Arcturus Modpack Manager';
    }

    public static function canAccess(): bool
    {
        return Access::admin();
    }

    public function mount(): void
    {
        $this->refreshReadiness();
    }

    public function refreshReadiness(): void
    {
        Access::requireAdmin();
        $this->report = app(LocalNode::class)->status();
    }

    public function setupCommands(): array
    {
        Access::requireAdmin();
        $install = 'sudo install -o root -g root -m 0755 '.escapeshellarg(plugin_path('arcturus-modpack-manager', 'node-worker/setup-local-node.py')).' /usr/local/sbin/arcturus-local-node-setup';

        return Node::orderBy('name')->get(['id', 'name', 'daemon_base'])->map(fn ($node) => [
            'name' => $node->name, 'id' => $node->id,
            'command' => $install."\n".'sudo /usr/bin/python3 /usr/local/sbin/arcturus-local-node-setup --panel '.escapeshellarg(base_path())
                .' --user www-data --node '.(int) $node->id.' --volume-root '.escapeshellarg($node->daemon_base),
        ])->all();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('nativeDefault')->label('Use Native Pelican / Wings')->action(fn () => $this->setDefault('native')),
            Action::make('refreshReadiness')->label('Refresh readiness')->color('gray')->action(fn () => $this->refreshReadiness()),
            Action::make('localDefault')->label('Use local for new eligible servers')->disabled(fn () => ! ($this->report['ready'] ?? false))
                ->action(fn () => $this->setDefault('local')),
            Action::make('remoteDefault')->label('Use remote default')->color('gray')->action(fn () => $this->setDefault('wings')),
        ];
    }

    private function setDefault(string $backend): void
    {
        Access::requireAdmin();
        try {
            $settings = app(PluginSettings::class);
            $settings->save(array_replace($settings->formData(), ['default_filesystem_backend' => $backend]));
            $this->refreshReadiness();
            Notification::make()->title('Default preparation backend saved')->body('Existing managed servers and protection policies are unchanged.')->success()->send();
        } catch (\RuntimeException $error) {
            Notification::make()->title('Local default unavailable')->body($error->getMessage())->danger()->send();
        }
    }
}
