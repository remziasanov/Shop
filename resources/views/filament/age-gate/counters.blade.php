@props(['counts' => [], 'productsUrl' => null, 'categoriesUrl' => null])

{{-- AG-14: how many products and categories carry a restriction (own or inherited). --}}
<dl class="grid gap-3 text-sm sm:grid-cols-2">
    <div>
        <dt class="text-gray-500 dark:text-gray-400">{{ __('age_gate.settings.counters.products') }}</dt>
        <dd class="text-lg font-semibold" data-count="products">{{ $counts['products_effective'] ?? 0 }}</dd>
        <dd class="text-gray-500 dark:text-gray-400">{{ __('age_gate.settings.counters.own', ['count' => $counts['products_own'] ?? 0]) }}</dd>
        @if ($productsUrl)
            <dd><a href="{{ $productsUrl }}" class="text-primary-600 underline">{{ __('age_gate.settings.counters.products_link') }}</a></dd>
        @endif
    </div>
    <div>
        <dt class="text-gray-500 dark:text-gray-400">{{ __('age_gate.settings.counters.categories') }}</dt>
        <dd class="text-lg font-semibold" data-count="categories">{{ $counts['categories_effective'] ?? 0 }}</dd>
        <dd class="text-gray-500 dark:text-gray-400">{{ __('age_gate.settings.counters.own', ['count' => $counts['categories_own'] ?? 0]) }}</dd>
        @if ($categoriesUrl)
            <dd><a href="{{ $categoriesUrl }}" class="text-primary-600 underline">{{ __('age_gate.settings.counters.categories_link') }}</a></dd>
        @endif
    </div>
</dl>
