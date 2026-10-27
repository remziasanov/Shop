<x-filament-widgets::widget>
    <x-filament::section :heading="__('dashboard.import.heading')">
        @php($import = $this->import())
        @if ($import === null)
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('dashboard.import.none') }}</p>
        @else
            <div class="space-y-2 text-sm">
                <div class="flex flex-wrap items-center gap-2">
                    <x-filament::badge :color="$this->badgeColor($import->status)">{{ $import->status->label() }}</x-filament::badge>
                    <span class="text-gray-500 dark:text-gray-400">{{ ($import->finished_at ?? $import->created_at)?->format('d.m.Y H:i') }}</span>
                </div>
                <p class="text-gray-950 dark:text-white">{{ $import->original_name }}</p>
                <p class="text-gray-500 dark:text-gray-400">
                    {{ __('dashboard.import.totals', [
                        'rows' => $import->rows_total,
                        'created' => $import->created_count,
                        'updated' => $import->updated_count,
                        'errors' => $import->error_count,
                    ]) }}
                </p>
                <p class="flex gap-4">
                    <a href="{{ $this->url($import) }}" class="font-medium text-primary-600 hover:underline dark:text-primary-400">{{ __('dashboard.import.open') }}</a>
                    <a href="{{ $this->listUrl() }}" class="font-medium text-primary-600 hover:underline dark:text-primary-400">{{ __('dashboard.import.all') }}</a>
                </p>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
