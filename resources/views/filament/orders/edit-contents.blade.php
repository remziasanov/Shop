<x-filament-panels::page>
    <form wire:submit="openPreview" class="space-y-6">
        {{ $this->form }}

        <div class="flex flex-wrap items-center gap-3">
            <x-filament::button type="submit" icon="heroicon-o-eye" wire:target="openPreview">
                {{ __('order_edit.page.preview_button') }}
            </x-filament::button>

            <x-filament::button
                color="gray"
                tag="a"
                :href="\App\Filament\Resources\Orders\OrderResource::getUrl('view', ['record' => $this->order()])"
            >
                {{ __('order_edit.page.cancel') }}
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
