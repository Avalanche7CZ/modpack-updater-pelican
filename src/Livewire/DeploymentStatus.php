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

    public function mount(int $managedServerId): void { Access::requireAdmin(); $this->managedServerId = $managedServerId; }

    public function confirmRunningDeployment(string $operationId): void
    {
        Access::requireAdmin();
        $operation = Operation::where('managed_server_id', $this->managedServerId)->findOrFail($operationId);
        try {
            $service = app(\Arcturus\ModpackManager\Native\ManualStart::class);
            $review = $service->confirmRunningDeployment($operation->id, (int) auth()->id());
            $result = $service->confirmRunningDeployment($operation->id, (int) auth()->id(), true, $review['proof'], true, $review['target_version']);
            \Filament\Notifications\Notification::make()->title('Běh nasazení potvrdil admin')->body($result['message'])->success()->send();
        } catch (\Throwable $error) {
            report($error);
            \Filament\Notifications\Notification::make()->title('Nasazení nebylo uzavřeno')->body($error instanceof \RuntimeException ? $error->getMessage() : 'Inspect the private Panel log; the checkpoint remains active. No power or restore was sent.')->danger()->send();
        }
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
