@if ($canEdit)
    <div class="flex flex-wrap gap-2">
        <x-filament::button color="gray" type="button" wire:click="resetColors">{{ __('themes.admin.reset_colors') }}</x-filament::button>
        <x-filament::button color="gray" type="button" wire:click="resetFonts">{{ __('themes.admin.reset_fonts') }}</x-filament::button>
    </div>
@endif
