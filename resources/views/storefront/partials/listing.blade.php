{{-- Filters (SF-13), sort (SF-12), product grid (SF-15) and pagination (SF-14) shared by catalog, category and search. --}}
@php
    $facets ??= [];
    $chips ??= [];
    $activeCount = $filters->activeCount();
    $mobileColumns ??= 2;
    // MB-07: one column on a phone when the shop asks for it, otherwise two (and two from 480 px with one).
    $gridMobile = $mobileColumns === 1 ? 'grid-cols-1 min-[30rem]:grid-cols-2' : 'grid-cols-2';
@endphp
<div class="md:flex md:items-start md:gap-6" x-data="{ filtersOpen: false, sortOpen: false, isDesktop() { return window.matchMedia('(min-width: 768px)').matches } }" @keydown.escape.window="filtersOpen = false">
    <aside class="mb-4 md:mb-0 md:w-64 md:flex-none" aria-label="{{ __('storefront.filters.title') }}">
        {{-- MB-07: on a phone «Фильтры» opens a slide-over and «Сортировка» a bottom sheet (both close with the back gesture). --}}
        <div class="flex gap-2 md:hidden">
            <button type="button" x-cloak class="flex min-h-11 flex-1 items-center justify-between rounded border border-slate-300 px-3 py-2 text-sm font-medium"
                    aria-haspopup="dialog" aria-controls="filters-panel" :aria-expanded="filtersOpen.toString()" @click="filtersOpen = true" data-open-filters>
                {{ __('storefront.filters.open', ['count' => $activeCount]) }}
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 5h18M6 12h12M10 19h4"/></svg>
            </button>
            <button type="button" x-cloak class="flex min-h-11 flex-1 items-center justify-between rounded border border-slate-300 px-3 py-2 text-sm font-medium"
                    aria-haspopup="dialog" aria-controls="sort-sheet" :aria-expanded="sortOpen.toString()" @click="sortOpen = true" data-open-sort>
                <span class="truncate">{{ __('mobile.sort.open') }}</span>
                <svg class="h-4 w-4 flex-none" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M7 4v16m0 0l-3-3m3 3l3-3M17 20V4m0 0l-3 3m3-3l3 3"/></svg>
            </button>
        </div>
        <div x-cloak x-show="filtersOpen" class="fixed inset-0 z-40 bg-slate-900/50 md:hidden" aria-hidden="true" @click="filtersOpen = false"></div>

        {{-- Plain GET form (works without JavaScript); on phones a slide-over, Alpine applies the choice 400 ms after the last change on wide screens (FL-13). --}}
        <form id="filters-panel" method="get" action="{{ $formAction }}" class="space-y-5 rounded-lg border border-slate-200 p-4 max-md:mt-2" x-overlay="filtersOpen" x-trap.noscroll="filtersOpen"
              :class="filtersOpen ? 'max-md:fixed max-md:inset-y-0 max-md:right-0 max-md:z-50 max-md:mt-0 max-md:w-[88%] max-md:max-w-sm max-md:overflow-y-auto max-md:rounded-none max-md:border-0 max-md:bg-white max-md:shadow-xl' : 'max-md:hidden'"
              :role="filtersOpen ? 'dialog' : null" :aria-modal="filtersOpen ? 'true' : null" aria-label="{{ __('storefront.filters.title') }}"
              @change.debounce.400ms="isDesktop() && $el.requestSubmit()">
            <div x-cloak class="flex items-center justify-between md:hidden">
                <span class="text-base font-semibold">{{ __('storefront.filters.title') }}</span>
                <button type="button" class="flex h-11 w-11 items-center justify-center rounded text-slate-600 hover:bg-slate-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary" aria-label="{{ __('storefront.filters.close') }}" @click="filtersOpen = false">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 6l12 12M18 6L6 18"/></svg>
                </button>
            </div>

            @foreach ($extraQuery as $name => $value)
                <input type="hidden" name="{{ $name }}" value="{{ $value }}">
            @endforeach

            {{-- On a phone sorting lives in the bottom sheet; the select stays in the form (it still carries the current sort) and is hidden once Alpine runs. --}}
            <div x-bind:class="'max-md:hidden'">
                <label for="sort" class="mb-1 block text-sm font-semibold">{{ __('storefront.sort.label') }}</label>
                <select id="sort" name="sort" class="w-full rounded border border-slate-300 px-2 py-2 text-sm focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary" @change="$el.form.requestSubmit()">
                    @foreach ($sortOptions as $value => $label)
                        <option value="{{ $value }}" @selected($filters->sort->value === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            @if ($showPrices)
            <fieldset>
                <legend class="mb-1 text-sm font-semibold">{{ __('storefront.filters.price') }}</legend>
                <div class="flex items-center gap-2">
                    <label for="price_from" class="sr-only">{{ __('storefront.filters.price_from') }}</label>
                    <input id="price_from" name="price_from" type="text" inputmode="decimal" value="{{ $filters->priceFromInput() }}" placeholder="{{ __('storefront.filters.price_from') }}"
                           class="w-full rounded border border-slate-300 px-2 py-2 text-sm focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary">
                    <span aria-hidden="true">–</span>
                    <label for="price_to" class="sr-only">{{ __('storefront.filters.price_to') }}</label>
                    <input id="price_to" name="price_to" type="text" inputmode="decimal" value="{{ $filters->priceToInput() }}" placeholder="{{ __('storefront.filters.price_to') }}"
                           class="w-full rounded border border-slate-300 px-2 py-2 text-sm focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary">
                </div>
            </fieldset>
            @endif

            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" name="in_stock" value="1" @checked($filters->inStock) class="h-4 w-4 rounded border-slate-300 accent-primary">
                {{ __('storefront.filters.in_stock') }}
            </label>

            @if ($brands !== [])
                <fieldset>
                    <legend class="mb-1 text-sm font-semibold">{{ __('storefront.filters.brands') }}</legend>
                    <div class="max-h-48 space-y-1 overflow-y-auto">
                        @foreach ($brands as $brand)
                            <label class="flex items-center gap-2 text-sm">
                                <input type="checkbox" name="brands[]" value="{{ $brand }}" @checked(in_array($brand, $filters->brands, true)) class="h-4 w-4 rounded border-slate-300 accent-primary">
                                {{ $brand }}
                            </label>
                        @endforeach
                    </div>
                </fieldset>
            @endif

            @if ($flagOptions !== [])
            <fieldset>
                <legend class="mb-1 text-sm font-semibold">{{ __('storefront.filters.labels') }}</legend>
                <div class="space-y-1">
                    @foreach ($flagOptions as $flag)
                        <label class="flex items-center gap-2 text-sm">
                            <input type="checkbox" name="flags[]" value="{{ $flag }}" @checked(in_array($flag, $filters->flags, true)) class="h-4 w-4 rounded border-slate-300 accent-primary">
                            {{ __('storefront.labels.'.$flag) }}
                        </label>
                    @endforeach
                </div>
            </fieldset>
            @endif

            @if ($facets !== [])
                @include('storefront.partials.attribute-filters', ['facets' => $facets])
            @endif

            @if ($optionToggle ?? false)
                {{-- VR-19: only cards that have an available variant (with the selected options). --}}
                <label class="flex min-h-11 items-center gap-2 border-t border-slate-200 pt-1 text-sm">
                    <input type="checkbox" name="{{ \App\Services\Catalog\OptionSelection::AVAILABLE_PARAM }}" value="1" @checked($filters->options->availableOnly) class="h-5 w-5 flex-none rounded border-slate-300 accent-primary">
                    {{ __('variant_storefront.filters.only_available') }}
                </label>
            @endif

            <div class="flex gap-2 max-md:sticky max-md:bottom-0 max-md:-mx-4 max-md:-mb-4 max-md:bg-white max-md:p-4 max-md:pb-[calc(1rem+var(--shop-safe-bottom))] max-md:shadow-[0_-1px_0_0_rgb(226_232_240)]">
                <button type="submit" class="min-h-11 flex-1 rounded bg-primary px-3 py-2 text-sm font-medium text-white hover:opacity-90 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">
                    <span class="md:hidden">{{ trans_choice('storefront.filters.show_products', $paginator->total()) }}</span>
                    <span class="max-md:hidden">{{ __('storefront.filters.apply') }}</span>
                </button>
                @if ($filters->hasFilters() || $filters->sort->value !== 'default')
                    <a href="{{ $resetUrl }}" class="flex min-h-11 items-center rounded border border-slate-300 px-3 py-2 text-sm hover:bg-slate-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary">{{ __('storefront.filters.reset') }}</a>
                @endif
            </div>
        </form>
    </aside>

    <div class="min-w-0 flex-1">
        @if ($chips !== [])
            <div class="mb-3 flex flex-wrap items-center gap-2" role="group" aria-label="{{ __('storefront.filters.active') }}">
                @foreach ($chips as $chip)
                    <a href="{{ $chip->url }}" aria-label="{{ $chip->removeLabel }}" class="inline-flex min-h-11 items-center gap-1.5 rounded-full bg-slate-100 px-3 text-sm hover:bg-slate-200 focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary">
                        {{ $chip->label }}
                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 6l12 12M18 6L6 18"/></svg>
                    </a>
                @endforeach
                <a href="{{ $resetUrl }}" class="inline-flex min-h-11 items-center px-2 text-sm text-primary underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary">{{ __('storefront.filters.reset_all') }}</a>
            </div>
        @endif

        <p class="mb-3 text-sm text-slate-500" aria-live="polite">{{ trans_choice('storefront.catalog.products_count', $paginator->total()) }}</p>

        @if ($cards === [])
            <p class="rounded-lg border border-dashed border-slate-300 p-6 text-center text-slate-600">{{ $emptyText ?? __('storefront.catalog.empty') }}</p>
        @else
            {{-- MB-07: «Показать ещё» (phones) loads the next page into the grid and sets ?page=N; the numbered pagination below stays in the markup for robots and visitors without JavaScript. --}}
            <div x-data="loadMore(@js(['loaded' => __('mobile.listing.loaded', ['count' => ':count'])]))">
                <ul class="grid {{ $gridMobile }} gap-3 md:grid-cols-2 md:gap-4 lg:grid-cols-3 {{ $gridColumns === 3 ? '' : 'xl:grid-cols-4' }}" data-listing-grid>
                    @foreach ($cards as $card)
                        <li>@include('storefront.partials.product-card', ['view' => $card, 'eager' => $loop->index < 4])</li>
                    @endforeach
                </ul>

                @if ($paginator->hasMorePages())
                    <div class="mt-6 text-center md:hidden" x-ref="wrap" data-load-more data-next="{{ $paginator->nextPageUrl() }}">
                        <p class="mb-2 text-sm text-slate-500">{{ __('mobile.listing.shown', ['shown' => $paginator->lastItem(), 'total' => $paginator->total()]) }}</p>
                        <button type="button" x-cloak x-ref="more" data-next="{{ $paginator->nextPageUrl() }}" @click="load()" :disabled="busy" :aria-busy="busy.toString()"
                                class="inline-flex min-h-11 w-full items-center justify-center rounded border border-primary px-6 py-2 font-medium text-primary hover:bg-slate-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:opacity-60" data-load-more-button>
                            <span x-show="! busy">{{ __('mobile.listing.load_more') }}</span>
                            <span x-show="busy" x-cloak>{{ __('mobile.listing.loading') }}</span>
                        </button>
                        <p x-cloak x-show="failed" class="mt-2 text-sm text-red-700" role="alert">{{ __('mobile.listing.error') }}</p>
                    </div>
                @endif
                <p class="sr-only" role="status" aria-live="polite" x-text="status"></p>

                {{-- With the button on a phone the numbered pagination is hidden visually (it stays in the DOM). --}}
                <div @if ($paginator->hasMorePages()) x-bind:class="'max-md:hidden'" @endif data-pagination>
                    {{ $paginator->links('storefront.partials.pagination') }}
                </div>
            </div>
        @endif
    </div>

    {{-- MB-07: sorting bottom sheet (phones). Links keep every filter of the address. --}}
    <x-storefront.sheet id="sort-sheet" :title="__('mobile.sort.title')" model="sortOpen">
        <ul class="divide-y divide-slate-100">
            @foreach ($sortOptions as $value => $label)
                @php($current = $filters->sort->value === $value)
                <li>
                    <a href="{{ request()->fullUrlWithQuery(['sort' => $value === 'default' ? null : $value, 'page' => null]) }}" @if ($current) aria-current="true" @endif
                       class="flex min-h-12 items-center justify-between gap-3 px-1 py-2 focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary {{ $current ? 'font-semibold text-primary' : '' }}" data-sort-option="{{ $value }}">
                        {{ $label }}
                        @if ($current)
                            <svg class="h-5 w-5 flex-none" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                        @endif
                    </a>
                </li>
            @endforeach
        </ul>
    </x-storefront.sheet>
</div>
