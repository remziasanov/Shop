{{-- Filters (SF-13), sort (SF-12), product grid (SF-15) and pagination (SF-14) shared by catalog, category and search. --}}
<div class="md:flex md:items-start md:gap-6" x-data="{ filtersOpen: false }">
    <aside class="mb-4 md:mb-0 md:w-64 md:flex-none" aria-label="{{ __('storefront.filters.title') }}">
        <button type="button" x-cloak class="flex w-full items-center justify-between rounded border border-slate-300 px-3 py-2 text-sm font-medium md:hidden"
                aria-controls="filters-panel" :aria-expanded="filtersOpen.toString()" @click="filtersOpen = ! filtersOpen">
            {{ __('storefront.filters.show') }}
            <svg class="h-4 w-4 transition" :class="filtersOpen ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
        </button>

        <form id="filters-panel" method="get" action="{{ $formAction }}" class="space-y-5 rounded-lg border border-slate-200 p-4 max-md:mt-2" :class="{ 'max-md:hidden': ! filtersOpen }">
            @foreach ($extraQuery as $name => $value)
                <input type="hidden" name="{{ $name }}" value="{{ $value }}">
            @endforeach

            <div>
                <label for="sort" class="mb-1 block text-sm font-semibold">{{ __('storefront.sort.label') }}</label>
                <select id="sort" name="sort" class="w-full rounded border border-slate-300 px-2 py-2 text-sm focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary" @change="$el.form.requestSubmit()">
                    @foreach ($sortOptions as $value => $label)
                        <option value="{{ $value }}" @selected($filters->sort->value === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>

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

            <fieldset>
                <legend class="mb-1 text-sm font-semibold">{{ __('storefront.filters.labels') }}</legend>
                <div class="space-y-1">
                    @foreach (['hit', 'new', 'sale'] as $flag)
                        <label class="flex items-center gap-2 text-sm">
                            <input type="checkbox" name="flags[]" value="{{ $flag }}" @checked(in_array($flag, $filters->flags, true)) class="h-4 w-4 rounded border-slate-300 accent-primary">
                            {{ __('storefront.labels.'.$flag) }}
                        </label>
                    @endforeach
                </div>
            </fieldset>

            <div class="flex gap-2">
                <button type="submit" class="flex-1 rounded bg-primary px-3 py-2 text-sm font-medium text-white hover:opacity-90 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">{{ __('storefront.filters.apply') }}</button>
                @if ($filters->hasFilters() || $filters->sort->value !== 'default')
                    <a href="{{ $formAction }}{{ $extraQuery !== [] ? '?'.http_build_query($extraQuery) : '' }}" class="rounded border border-slate-300 px-3 py-2 text-sm hover:bg-slate-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary">{{ __('storefront.filters.reset') }}</a>
                @endif
            </div>
        </form>
    </aside>

    <div class="min-w-0 flex-1">
        <p class="mb-3 text-sm text-slate-500" aria-live="polite">{{ trans_choice('storefront.catalog.products_count', $paginator->total()) }}</p>

        @if ($cards === [])
            <p class="rounded-lg border border-dashed border-slate-300 p-6 text-center text-slate-600">{{ $emptyText ?? __('storefront.catalog.empty') }}</p>
        @else
            <ul class="grid grid-cols-2 gap-3 md:gap-4 lg:grid-cols-3 {{ $gridColumns === 3 ? '' : 'xl:grid-cols-4' }}">
                @foreach ($cards as $card)
                    <li>@include('storefront.partials.product-card', ['view' => $card, 'eager' => $loop->index < 4])</li>
                @endforeach
            </ul>
        @endif

        {{ $paginator->links('storefront.partials.pagination') }}
    </div>
</div>
