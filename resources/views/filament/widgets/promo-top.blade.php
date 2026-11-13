<x-filament-widgets::widget>
    <x-filament::section :heading="__('promo_admin.widget.heading')">
        <div class="space-y-4 text-sm">
            <div>
                <h3 class="mb-1 font-medium text-gray-950 dark:text-white">{{ __('promo_admin.widget.codes') }}</h3>
                @forelse ($this->codes() as $row)
                    <p class="flex flex-wrap justify-between gap-2 py-1">
                        @if ($row['url'])
                            <a href="{{ $row['url'] }}" class="font-medium text-primary-600 hover:underline dark:text-primary-400">{{ $row['label'] }}</a>
                        @else
                            <span class="font-medium">{{ $row['label'] }}</span>
                        @endif
                        <span class="text-gray-500 dark:text-gray-400">{{ __('promo_admin.widget.uses', ['uses' => $row['uses'], 'discount' => $row['discount']]) }}</span>
                    </p>
                @empty
                    <p class="text-gray-500 dark:text-gray-400">{{ __('promo_admin.widget.empty_codes') }}</p>
                @endforelse
                <a href="{{ $this->codesUrl() }}" class="font-medium text-primary-600 hover:underline dark:text-primary-400">{{ __('promo_admin.widget.all_codes') }}</a>
            </div>

            @if ($this->showLinks())
                <div>
                    <h3 class="mb-1 font-medium text-gray-950 dark:text-white">{{ __('promo_admin.widget.links') }}</h3>
                    @forelse ($this->links() as $row)
                        <p class="flex flex-wrap justify-between gap-2 py-1">
                            <a href="{{ $row['url'] }}" class="font-medium text-primary-600 hover:underline dark:text-primary-400">{{ $row['label'] }}</a>
                            <span class="text-gray-500 dark:text-gray-400">{{ __('promo_admin.widget.clicks', ['clicks' => $row['clicks'], 'orders' => $row['orders']]) }}</span>
                        </p>
                    @empty
                        <p class="text-gray-500 dark:text-gray-400">{{ __('promo_admin.widget.empty_links') }}</p>
                    @endforelse
                    <a href="{{ $this->linksUrl() }}" class="font-medium text-primary-600 hover:underline dark:text-primary-400">{{ __('promo_admin.widget.all_links') }}</a>
                </div>
            @endif
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
