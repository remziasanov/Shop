{{-- Filters (SF-13), sort (SF-12), product grid (SF-15) and pagination (SF-14) shared by catalog, category and search. --}}
@php
    $facets ??= [];
    $chips ??= [];
    $activeCount = $filters->activeCount();
    $mobileColumns ??= 2;
    // TH-13: L1 keeps the filters in a left column; L2 and L3 put them above the grid as chips and a panel that opens from them
    // (as in the reference mockups, the dense L3 grid takes the full width). The filter set, sorting and load-more are the same.
    $topFilters = in_array($theme->blocks['listing'] ?? 'L1', ['L2', 'L3'], true);
@endphp
<div @unless ($topFilters) class="md:flex md:items-start md:gap-6" @endunless x-data="{ filtersOpen: false, sortOpen: false, panelOpen: (() => { try { return sessionStorage.getItem('shop-filters-panel') === '1' } catch (e) { return false } })(), isDesktop() { return window.matchMedia('(min-width: 768px)').matches } }" x-init="$watch('panelOpen', (open) => { try { sessionStorage.setItem('shop-filters-panel', open ? '1' : '0') } catch (e) {} })" @keydown.escape.window="filtersOpen = false; panelOpen = false" data-listing="{{ $theme->blocks['listing'] ?? 'L1' }}">
    <aside class="mb-4 {{ $topFilters ? '' : 'md:mb-0 md:w-64 md:flex-none' }}" aria-label="{{ __('storefront.filters.title') }}">
        {{-- MB-07: on a phone «Фильтры» opens a slide-over and «Сортировка» a bottom sheet (both close with the back gesture). --}}
        <div class="flex gap-2 md:hidden">
            <button type="button" x-cloak class="flex min-h-11 flex-1 items-center justify-between rounded-btn border border-line-strong px-3 py-2 text-sm font-medium"
                    aria-haspopup="dialog" aria-controls="filters-panel" :aria-expanded="filtersOpen.toString()" @click="filtersOpen = true" data-open-filters>
                {{ __('storefront.filters.open', ['count' => $activeCount]) }}
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 5h18M6 12h12M10 19h4"/></svg>
            </button>
            <button type="button" x-cloak class="flex min-h-11 flex-1 items-center justify-between rounded-btn border border-line-strong px-3 py-2 text-sm font-medium"
                    aria-haspopup="dialog" aria-controls="sort-sheet" :aria-expanded="sortOpen.toString()" @click="sortOpen = true" data-open-sort>
                <span class="truncate">{{ __('mobile.sort.open') }}</span>
                <svg class="h-4 w-4 flex-none" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M7 4v16m0 0l-3-3m3 3l3-3M17 20V4m0 0l-3 3m3-3l3 3"/></svg>
            </button>
        </div>
        @if ($topFilters)
            {{-- TH-13 (L2, L3), wide screens: chips above the grid. Plain links (they work without JavaScript); «Фильтры» opens the panel with the whole filter set, which is always open without JavaScript. --}}
            @php
                $queryNow = request()->query();
                $chipUrl = fn (array $change): string => request()->fullUrlWithQuery($change + ['page' => null]);
                $chipClass = 'inline-flex min-h-11 items-center gap-1.5 rounded-full border px-4 text-sm font-medium focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary';
                $chipOff = 'border-line-strong hover:bg-surface-2 active:bg-surface-3';
                $chipOn = 'border-primary bg-primary text-on-primary hover:opacity-90 active:opacity-80';
            @endphp
            <div x-cloak class="mb-1 hidden flex-wrap items-center gap-2 md:flex" role="group" aria-label="{{ __('storefront.filters.title') }}" data-filter-chips>
                <button type="button" class="{{ $chipClass }} {{ $chipOff }}" aria-controls="filters-panel" :aria-expanded="panelOpen.toString()" :class="panelOpen ? '{{ $chipOn }}' : ''" @click="panelOpen = ! panelOpen" data-toggle-filters>
                    {{ __('storefront.filters.open', ['count' => $activeCount]) }}
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 5h18M6 12h12M10 19h4"/></svg>
                </button>
                <a href="{{ $chipUrl(['in_stock' => $filters->inStock ? null : '1']) }}" class="{{ $chipClass }} {{ $filters->inStock ? $chipOn : $chipOff }}" @if ($filters->inStock) aria-current="true" @endif data-chip="in_stock">{{ __('storefront.filters.in_stock') }}</a>
                @foreach ($flagOptions as $flag)
                    @php($flagOn = in_array($flag, $filters->flags, true))
                    @php($flagsNext = $flagOn ? array_values(array_diff($filters->flags, [$flag])) : [...$filters->flags, $flag])
                    <a href="{{ $chipUrl(['flags' => $flagsNext === [] ? null : $flagsNext]) }}" class="{{ $chipClass }} {{ $flagOn ? $chipOn : $chipOff }}" @if ($flagOn) aria-current="true" @endif data-chip="{{ $flag }}">{{ __('storefront.labels.'.$flag) }}</a>
                @endforeach
                <div class="ml-auto flex items-center gap-2">
                    <label for="sort-top" class="text-sm text-muted">{{ __('storefront.sort.label') }}</label>
                    <select id="sort-top" class="min-h-11 rounded-btn border border-line-strong px-2 py-2 text-sm focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary"
                            @change="$refs.sortSelect.value = $el.value; $refs.sortSelect.form.requestSubmit()">
                        @foreach ($sortOptions as $value => $label)
                            <option value="{{ $value }}" @selected($filters->sort->value === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        @endif
        <div x-cloak x-show="filtersOpen" class="fixed inset-0 z-40 bg-ink/50 md:hidden" aria-hidden="true" @click="filtersOpen = false"></div>

        {{-- Plain GET form (works without JavaScript); on phones a slide-over, Alpine applies the choice 400 ms after the last change on wide screens (FL-13). --}}
        <form id="filters-panel" method="get" action="{{ $formAction }}" class="space-y-5 rounded-card border border-line p-4 max-md:mt-2{{ $topFilters ? ' md:mt-3 md:grid md:grid-cols-3 md:items-start md:gap-x-6 md:gap-y-5 md:space-y-0' : '' }}" @if ($topFilters) data-filter-panel="top" :data-open="panelOpen ? 'true' : null" @endif x-overlay="filtersOpen" x-trap.noscroll="filtersOpen"
              :class="filtersOpen ? 'max-md:fixed max-md:inset-y-0 max-md:right-0 max-md:z-50 max-md:mt-0 max-md:w-[88%] max-md:max-w-sm max-md:overflow-y-auto max-md:rounded-none max-md:border-0 max-md:bg-surface max-md:shadow-xl' : 'max-md:hidden'"
              :role="filtersOpen ? 'dialog' : null" :aria-modal="filtersOpen ? 'true' : null" aria-label="{{ __('storefront.filters.title') }}"
              @change.debounce.400ms="isDesktop() && $el.requestSubmit()">
            <div x-cloak class="flex items-center justify-between md:hidden">
                <span class="text-base font-semibold">{{ __('storefront.filters.title') }}</span>
                <button type="button" class="flex h-11 w-11 items-center justify-center rounded-btn text-ink-mid hover:bg-surface-3 focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary" aria-label="{{ __('storefront.filters.close') }}" @click="filtersOpen = false">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 6l12 12M18 6L6 18"/></svg>
                </button>
            </div>

            @foreach ($extraQuery as $name => $value)
                <input type="hidden" name="{{ $name }}" value="{{ $value }}">
            @endforeach

            {{-- On a phone sorting lives in the bottom sheet; the select stays in the form (it still carries the current sort) and is hidden once Alpine runs. --}}
            <div x-bind:class="'{{ $topFilters ? 'max-md:hidden md:hidden' : 'max-md:hidden' }}'">
                <label for="sort" class="mb-1 block text-sm font-semibold">{{ __('storefront.sort.label') }}</label>
                <select id="sort" name="sort" x-ref="sortSelect" class="w-full rounded-btn border border-line-strong px-2 py-2 text-sm focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary" @change="$el.form.requestSubmit()">
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
                           class="w-full rounded-btn border border-line-strong px-2 py-2 text-sm focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary">
                    <span aria-hidden="true">–</span>
                    <label for="price_to" class="sr-only">{{ __('storefront.filters.price_to') }}</label>
                    <input id="price_to" name="price_to" type="text" inputmode="decimal" value="{{ $filters->priceToInput() }}" placeholder="{{ __('storefront.filters.price_to') }}"
                           class="w-full rounded-btn border border-line-strong px-2 py-2 text-sm focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary">
                </div>
            </fieldset>
            @endif

            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" name="in_stock" value="1" @checked($filters->inStock) class="h-4 w-4 rounded-btn border-line-strong accent-primary">
                {{ __('storefront.filters.in_stock') }}
            </label>

            @if ($brands !== [])
                <fieldset>
                    <legend class="mb-1 text-sm font-semibold">{{ __('storefront.filters.brands') }}</legend>
                    <div class="max-h-48 space-y-1 overflow-y-auto">
                        @foreach ($brands as $brand)
                            <label class="flex items-center gap-2 text-sm">
                                <input type="checkbox" name="brands[]" value="{{ $brand }}" @checked(in_array($brand, $filters->brands, true)) class="h-4 w-4 rounded-btn border-line-strong accent-primary">
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
                            <input type="checkbox" name="flags[]" value="{{ $flag }}" @checked(in_array($flag, $filters->flags, true)) class="h-4 w-4 rounded-btn border-line-strong accent-primary">
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
                <label class="flex min-h-11 items-center gap-2 border-t border-line pt-1 text-sm">
                    <input type="checkbox" name="{{ \App\Services\Catalog\OptionSelection::AVAILABLE_PARAM }}" value="1" @checked($filters->options->availableOnly) class="h-5 w-5 flex-none rounded-btn border-line-strong accent-primary">
                    {{ __('variant_storefront.filters.only_available') }}
                </label>
            @endif

            <div class="flex gap-2 {{ $topFilters ? 'md:col-span-full ' : '' }}max-md:sticky max-md:bottom-0 max-md:-mx-4 max-md:-mb-4 max-md:bg-surface max-md:p-4 max-md:pb-[calc(1rem+var(--shop-safe-bottom))] max-md:shadow-[0_-1px_0_0_var(--shop-line)]">
                <button type="submit" class="min-h-11 flex-1 rounded-btn bg-primary px-3 py-2 text-sm font-medium text-on-primary hover:opacity-90 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">
                    <span class="md:hidden">{{ trans_choice('storefront.filters.show_products', $paginator->total()) }}</span>
                    <span class="max-md:hidden">{{ __('storefront.filters.apply') }}</span>
                </button>
                @if ($filters->hasFilters() || $filters->sort->value !== 'default')
                    <a href="{{ $resetUrl }}" class="flex min-h-11 items-center rounded-btn border border-line-strong px-3 py-2 text-sm hover:bg-surface-2 focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary">{{ __('storefront.filters.reset') }}</a>
                @endif
            </div>
        </form>
    </aside>

    <div class="min-w-0 flex-1">
        @if ($chips !== [])
            <div class="mb-3 flex flex-wrap items-center gap-2" role="group" aria-label="{{ __('storefront.filters.active') }}">
                @foreach ($chips as $chip)
                    <a href="{{ $chip->url }}" aria-label="{{ $chip->removeLabel }}" class="inline-flex min-h-11 items-center gap-1.5 rounded-full bg-surface-3 px-3 text-sm hover:bg-line focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary">
                        {{ $chip->label }}
                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 6l12 12M18 6L6 18"/></svg>
                    </a>
                @endforeach
                <a href="{{ $resetUrl }}" class="inline-flex min-h-11 items-center px-2 text-sm text-primary underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary">{{ __('storefront.filters.reset_all') }}</a>
            </div>
        @endif

        <p class="mb-3 text-sm text-muted" aria-live="polite">{{ trans_choice('storefront.catalog.products_count', $paginator->total()) }}</p>

        @if ($cards === [])
            <p class="rounded-card border border-dashed border-line-strong p-6 text-center text-ink-mid">{{ $emptyText ?? __('storefront.catalog.empty') }}</p>
        @else
            {{-- MB-07: «Показать ещё» (phones) loads the next page into the grid and sets ?page=N; the numbered pagination below stays in the markup for robots and visitors without JavaScript. --}}
            <div x-data="loadMore(@js(['loaded' => __('mobile.listing.loaded', ['count' => ':count'])]))">
                <x-storefront.card-grid :theme="$theme" :columns="$gridColumns" :mobile-columns="$mobileColumns" data-listing-grid>
                    @foreach ($cards as $card)
                        <li>@include('storefront.partials.product-card', ['view' => $card, 'eager' => $loop->index < 4, 'number' => ($paginator->firstItem() ?? 1) + $loop->index])</li>
                    @endforeach
                </x-storefront.card-grid>

                @if ($paginator->hasMorePages())
                    <div class="mt-6 text-center md:hidden" x-ref="wrap" data-load-more data-next="{{ $paginator->nextPageUrl() }}">
                        <p class="mb-2 text-sm text-muted" data-load-count>{{ __('mobile.listing.shown', ['shown' => $paginator->lastItem(), 'total' => $paginator->total()]) }}</p>
                        <button type="button" x-cloak x-ref="more" data-next="{{ $paginator->nextPageUrl() }}" @click="load()" :disabled="busy" :aria-busy="busy.toString()"
                                class="inline-flex min-h-11 w-full items-center justify-center rounded-btn border border-primary px-6 py-2 font-medium text-primary hover:bg-surface-2 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:opacity-60" data-load-more-button>
                            <span x-show="! busy">{{ __('mobile.listing.load_more') }}</span>
                            <span x-show="busy" x-cloak>{{ __('mobile.listing.loading') }}</span>
                        </button>
                        <p x-cloak x-show="failed" class="mt-2 text-sm text-danger" role="alert">{{ __('mobile.listing.error') }}</p>
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
        <ul class="divide-y divide-hairline">
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
