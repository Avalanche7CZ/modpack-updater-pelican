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

    public function render()
    {
        Access::requireAdmin();
        $operation = Operation::where('managed_server_id', $this->managedServerId)->where('kind', 'deploy')->latest('created_at')->first();
        $context = []; $receiptReadable = true;
        if ($operation) {
            try { $context = $operation->context ?? []; }
            catch (\Illuminate\Contracts\Encryption\DecryptException) {
                // Status must remain visible. Never reinterpret ciphertext as plaintext
                // or alter/replay a deployment because its receipt cannot be decrypted.
                $receiptReadable = false;
            }
        }
        return view('arcturus-modpack-manager::deployment-status', compact('operation', 'context', 'receiptReadable'));
    }
}
