<?php

declare(strict_types=1);
namespace Arcturus\ModpackManager\Livewire;

use Arcturus\ModpackManager\Models\Operation;
use Arcturus\ModpackManager\Security\Access;
use Livewire\Attributes\Locked;
use Livewire\Component;

final class DeploymentStatus extends Component
{
    #[Locked]
    public int $managedServerId;

    #[Locked]
    public ?array $deploymentReview = null;

    #[Locked]
    public ?array $configReview = null;

    #[Locked]
    public ?array $configurationSetReview = null;

    public function reviewCurrentConfiguration(string $operationId): void
    {
        Access::requireAdmin();
        Operation::where('managed_server_id', $this->managedServerId)->findOrFail($operationId);
        $this->configurationSetReview = null;
        try { $this->configurationSetReview = app(\Arcturus\ModpackManager\Native\ManualStart::class)->acceptCurrentConfiguration($operationId, (int) auth()->id()); }
        catch (\Throwable $error) { $this->reviewFailure($error); }
    }

    public function acceptCurrentConfiguration(): void
    {
        Access::requireAdmin();
        $review = $this->configurationSetReview;
        if (! ($review['can_confirm'] ?? false) || empty($review['proof'])) { return; }
        Operation::where('managed_server_id', $this->managedServerId)->findOrFail($review['operation_id']);
        try {
            $result = app(\Arcturus\ModpackManager\Native\ManualStart::class)->acceptCurrentConfiguration($review['operation_id'], (int) auth()->id(), true, $review['proof']);
            $this->configurationSetReview = null;
            \Filament\Notifications\Notification::make()->title('Nasazení dokončeno s přijatou lokální konfigurací')->body($result['message'])->success()->send();
        } catch (\Throwable $error) { $this->configurationSetReview = null; $this->reviewFailure($error); }
    }

    public function mount(int $managedServerId): void { Access::requireAdmin(); $this->managedServerId = $managedServerId; }

    public function confirmRunningDeployment(string $operationId): void
    {
        Access::requireAdmin();
        $operation = Operation::where('managed_server_id', $this->managedServerId)->findOrFail($operationId);
        try {
            $service = app(\Arcturus\ModpackManager\Native\ManualStart::class);
            $review = $service->confirmRunningDeployment($operation->id, (int) auth()->id());
            $this->deploymentReview = $review;
            if (! ($review['can_confirm'] ?? false)) { \Filament\Notifications\Notification::make()->title('Nasazené soubory vyžadují kontrolu')->body('Všechny rozdíly jsou uvedeny v kartě. Přijměte jednotlivé textové configy po kontrole diffu; striktní cesty musí souhlasit.')->warning()->send(); return; }
            $result = $service->confirmRunningDeployment($operation->id, (int) auth()->id(), true, $review['proof'], true, $review['target_version']);
            \Filament\Notifications\Notification::make()->title('Běh nasazení potvrdil admin')->body($result['message'])->success()->send();
        } catch (\Throwable $error) {
            report($error);
            \Filament\Notifications\Notification::make()->title('Nasazení nebylo uzavřeno')->body($error instanceof \RuntimeException ? $error->getMessage() : 'Inspect the private Panel log; the checkpoint remains active. No power or restore was sent.')->danger()->send();
        }
    }

    public function reviewRunningDeployment(string $operationId): void
    {
        Access::requireAdmin();
        Operation::where('managed_server_id', $this->managedServerId)->findOrFail($operationId);
        $this->deploymentReview = null; $this->configReview = null;
        try { $this->deploymentReview = app(\Arcturus\ModpackManager\Native\ManualStart::class)->confirmRunningDeployment($operationId, (int) auth()->id()); }
        catch (\Throwable $error) { $this->reviewFailure($error); }
    }

    public function reviewDeploymentConfig(string $operationId, string $path, string $sha): void
    {
        Access::requireAdmin();
        Operation::where('managed_server_id', $this->managedServerId)->findOrFail($operationId);
        $this->configReview = null;
        try { $this->configReview = app(\Arcturus\ModpackManager\Native\ManualStart::class)->deploymentConfigChange($operationId, (int) auth()->id(), $path, $sha); }
        catch (\Throwable $error) { $this->reviewFailure($error); }
    }

    public function acceptDeploymentConfig(): void
    {
        Access::requireAdmin();
        if ($this->configReview === null) { return; }
        $review = $this->configReview;
        Operation::where('managed_server_id', $this->managedServerId)->findOrFail($review['operation_id']);
        try {
            $result = app(\Arcturus\ModpackManager\Native\ManualStart::class)->deploymentConfigChange($review['operation_id'], (int) auth()->id(), $review['path'], $review['actual_sha256'], true, $review['proof']);
            $this->configReview = null;
            \Filament\Notifications\Notification::make()->title('Přijata konkrétní lokální změna')->body($result['message'])->success()->send();
            $this->reviewRunningDeployment($review['operation_id']);
        } catch (\Throwable $error) { $this->reviewFailure($error); }
    }

    private function reviewFailure(\Throwable $error): void
    {
        report($error);
        \Filament\Notifications\Notification::make()->title('Kontrola nebyla potvrzena')->body($error instanceof \RuntimeException ? $error->getMessage() : 'Inspect the private Panel log. No live files, power or backup were changed.')->danger()->send();
    }

    public function render()
    {
        Access::requireAdmin();
        $active = \Arcturus\ModpackManager\Models\ManagedServer::whereKey($this->managedServerId)->value('active_operation_id');
        $operation = ($active ? Operation::where('managed_server_id', $this->managedServerId)->where('kind', 'deploy')->find($active) : null)
            ?? Operation::where('managed_server_id', $this->managedServerId)->where('kind', 'deploy')->latest('created_at')->first();
        $context = []; $receiptReadable = true;
        if ($operation) {
            try { $context = $operation->context ?? []; }
            catch (\Illuminate\Contracts\Encryption\DecryptException) {
                // Status must remain visible. Never reinterpret ciphertext as plaintext
                // or alter/replay a deployment because its receipt cannot be decrypted.
                $receiptReadable = false;
            }
        }
        $canConfirmRunning = $receiptReadable && $operation && $operation->id === $active && \Arcturus\ModpackManager\Native\ManualStart::canConfirmRunningDeployment($operation);
        return view('arcturus-modpack-manager::deployment-status', compact('operation', 'context', 'receiptReadable', 'canConfirmRunning'));
    }
}
