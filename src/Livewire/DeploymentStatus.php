<?php

declare(strict_types=1);
namespace Arcturus\ModpackManager\Livewire;

use Arcturus\ModpackManager\Models\{DeploymentConfirmation, Operation};
use Arcturus\ModpackManager\Services\QueueDeploymentConfirmation;
use Arcturus\ModpackManager\Security\Access;
use Livewire\Attributes\Locked;
use Livewire\Component;

final class DeploymentStatus extends Component
{
    #[Locked]
    public int $managedServerId;

    #[Locked]
    public bool $showLast = true;

    #[Locked]
    public ?array $deploymentReview = null;

    #[Locked]
    public ?array $configReview = null;

    #[Locked]
    public ?array $configurationSetReview = null;

    #[Locked]
    public ?string $confirmationId = null;

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
        $this->queueConfirmation($review, true);
    }

    private function queueConfirmation(array $review, bool $configurationSet): void
    {
        try {
            $confirmation = app(QueueDeploymentConfirmation::class)->enqueue($review['operation_id'], (int) auth()->id(),
                (int) ($review['review_event_id'] ?? 0), $review['proof'], $configurationSet);
            $this->confirmationId = $confirmation->id;
            $this->configurationSetReview = $this->deploymentReview = $this->configReview = null;
        } catch (\Throwable $error) { $this->reviewFailure($error); }
    }

    public function retryConfirmation(string $confirmationId): void
    {
        Access::requireAdmin();
        $confirmation = DeploymentConfirmation::whereIn('deployment_id', Operation::where('managed_server_id', $this->managedServerId)->select('id'))
            ->findOrFail($confirmationId);
        try {
            $this->confirmationId = app(QueueDeploymentConfirmation::class)->retry($confirmation->id, (int) auth()->id())->id;
        } catch (\Throwable $error) { $this->reviewFailure($error); }
    }

    public function mount(int $managedServerId, bool $showLast = true): void
    {
        Access::requireAdmin();
        $this->managedServerId = $managedServerId;
        $this->showLast = $showLast;
    }

    public function confirmRunningDeployment(string $operationId): void
    {
        Access::requireAdmin();
        Operation::where('managed_server_id', $this->managedServerId)->findOrFail($operationId);
        $review = $this->deploymentReview;
        if (($review['operation_id'] ?? null) !== $operationId || ! ($review['can_confirm'] ?? false) || empty($review['proof'])) { return; }
        $this->queueConfirmation($review, false);
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
        $submitted = $this->confirmationId ? DeploymentConfirmation::find($this->confirmationId, ['id', 'deployment_id']) : null;
        $operation = ($active ? Operation::where('managed_server_id', $this->managedServerId)->where('kind', 'deploy')->find($active) : null)
            ?? ($submitted ? Operation::where('managed_server_id', $this->managedServerId)->find($submitted->deployment_id) : null)
            ?? ($this->showLast ? Operation::where('managed_server_id', $this->managedServerId)->where('kind', 'deploy')->latest('created_at')->latest('id')->first() : null);
        $isActive = $operation && $operation->id === $active;
        $context = []; $receiptReadable = true;
        if ($operation) {
            try { $context = $operation->context ?? []; }
            catch (\Illuminate\Contracts\Encryption\DecryptException) {
                // Status must remain visible. Never reinterpret ciphertext as plaintext
                // or alter/replay a deployment because its receipt cannot be decrypted.
                $receiptReadable = false;
            }
        }
        $canConfirmRunning = $receiptReadable && $isActive && \Arcturus\ModpackManager\Native\ManualStart::canConfirmRunningDeployment($operation);
        $pendingStates = QueueDeploymentConfirmation::PENDING;
        $placeholders = implode(', ', array_fill(0, count($pendingStates), '?'));
        $confirmation = $operation ? DeploymentConfirmation::where('deployment_id', $operation->id)
            ->orderByRaw("CASE WHEN state IN ($placeholders) THEN 0 ELSE 1 END", $pendingStates)
            ->latest('updated_at')->latest('review_event_id')->first(['id', 'state', 'actor_id', 'error', 'updated_at']) : null;
        $confirmationPending = $confirmation && in_array($confirmation->state, QueueDeploymentConfirmation::PENDING, true);
        return view('arcturus-modpack-manager::deployment-status', compact('operation', 'context', 'isActive', 'receiptReadable', 'canConfirmRunning', 'confirmation', 'confirmationPending'));
    }
}
