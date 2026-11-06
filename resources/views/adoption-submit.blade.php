<x-filament::button type="button" wire:click="create" wire:loading.attr="disabled" wire:target="create">
    {{ ($this->data['adoption_mode'] ?? 'draft') === 'monitor' ? 'Adopt for monitoring only' : 'Create adoption draft' }}
</x-filament::button>
