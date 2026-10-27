<x-filament-widgets::widget>
    <x-filament::section>
        <div class="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm" data-testid="legal-notice">
            <x-filament::icon icon="heroicon-o-scale" class="h-5 w-5 shrink-0 text-warning-600 dark:text-warning-400" />
            <span class="font-medium text-gray-950 dark:text-white">{{ __('dashboard.legal.notice', ['pages' => implode(', ', $this->titles())]) }}</span>
            <a href="{{ \App\Filament\Resources\Pages\PageResource::getUrl('index') }}" class="font-medium text-primary-600 hover:underline dark:text-primary-400">{{ __('dashboard.legal.open') }}</a>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
