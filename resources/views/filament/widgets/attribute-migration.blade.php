<x-filament-widgets::widget>
    <x-filament::section>
        <div class="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm" data-testid="attribute-migration-notice" wire:poll.10s>
            <x-filament::icon icon="heroicon-o-exclamation-triangle" class="h-5 w-5 shrink-0 text-warning-600 dark:text-warning-400" />
            <span class="font-medium text-gray-950 dark:text-white">{{ __('dashboard.attributes.notice') }}</span>
            <span class="text-gray-600 dark:text-gray-400">{{ __('dashboard.attributes.hint') }}</span>
            @if ($progress = $this->progress())
                <span class="font-medium text-gray-950 dark:text-white">{{ __('dashboard.attributes.progress', ['done' => $progress['done'], 'total' => $progress['total']]) }}</span>
            @endif
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
