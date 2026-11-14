@extends('storefront.layouts.app')

@section('content')
    <div class="mx-auto w-full max-w-4xl py-6">
        <p class="text-sm"><a href="{{ route('account') }}" class="inline-flex min-h-11 items-center text-primary underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary">{{ __('account.index.title') }}</a></p>
        <h1 class="mt-2 text-2xl font-bold md:text-3xl">{{ __('account.orders.title') }}</h1>
        <div class="mt-4">
            <x-account.errors />
        </div>

        {{-- CA-11: filters by type, status and period; sort by date. A plain GET form, it works without JavaScript. MB-09: on a phone the form is a slide-over opened by «Фильтры» (the back gesture closes it). --}}
        @php($activeFilters = count(array_filter([$filters['type'], $filters['status'], $filters['from'], $filters['until']])) + ($filters['sort'] === 'asc' ? 1 : 0))
        <div x-data="{ filtersOpen: false }" @keydown.escape.window="filtersOpen = false">
        <button type="button" x-cloak class="flex min-h-11 w-full items-center justify-between rounded-btn border border-line-strong px-3 py-2 text-sm font-medium md:hidden"
                aria-haspopup="dialog" aria-controls="orders-filters" :aria-expanded="filtersOpen.toString()" @click="filtersOpen = true" data-open-filters>
            {{ __('storefront.filters.open', ['count' => $activeFilters]) }}
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 5h18M6 12h12M10 19h4"/></svg>
        </button>
        <div x-cloak x-show="filtersOpen" class="fixed inset-0 z-40 bg-ink/50 md:hidden" aria-hidden="true" @click="filtersOpen = false"></div>
        <form id="orders-filters" method="GET" action="{{ route('customer.orders') }}" class="grid gap-3 rounded-card border border-line p-4 sm:grid-cols-2 lg:grid-cols-5" aria-label="{{ __('account.orders.filters') }}"
              x-overlay="filtersOpen" x-trap.noscroll="filtersOpen"
              :class="filtersOpen ? 'max-md:fixed max-md:inset-y-0 max-md:right-0 max-md:z-50 max-md:w-[88%] max-md:max-w-sm max-md:grid-cols-1 max-md:content-start max-md:overflow-y-auto max-md:rounded-none max-md:border-0 max-md:bg-surface max-md:pb-[calc(1rem+var(--shop-safe-bottom))] max-md:shadow-xl' : 'max-md:hidden'"
              :role="filtersOpen ? 'dialog' : null" :aria-modal="filtersOpen ? 'true' : null">
            <div x-cloak class="flex items-center justify-between md:hidden">
                <span class="text-base font-semibold">{{ __('mobile.cabinet.filters') }}</span>
                <button type="button" class="flex h-11 w-11 items-center justify-center rounded-btn text-ink-mid hover:bg-surface-3 focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary" aria-label="{{ __('mobile.cabinet.filters_close') }}" @click="filtersOpen = false">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 6l12 12M18 6L6 18"/></svg>
                </button>
            </div>
            <div>
                <label for="of-type" class="mb-1 block text-sm font-medium">{{ __('account.orders.type') }}</label>
                <select id="of-type" name="type" class="w-full rounded-btn border border-line-strong px-2 py-2 text-sm focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary">
                    <option value="">{{ __('account.orders.any') }}</option>
                    @foreach ($types as $value => $label)
                        <option value="{{ $value }}" @selected($filters['type'] === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="of-status" class="mb-1 block text-sm font-medium">{{ __('account.orders.status') }}</label>
                <select id="of-status" name="status" class="w-full rounded-btn border border-line-strong px-2 py-2 text-sm focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary">
                    <option value="">{{ __('account.orders.any') }}</option>
                    @foreach ($statuses as $value => $label)
                        <option value="{{ $value }}" @selected($filters['status'] === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="of-from" class="mb-1 block text-sm font-medium">{{ __('account.orders.from') }}</label>
                <input id="of-from" type="date" name="from" value="{{ $filters['from'] }}" class="w-full rounded-btn border border-line-strong px-2 py-2 text-sm focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary">
            </div>
            <div>
                <label for="of-until" class="mb-1 block text-sm font-medium">{{ __('account.orders.until') }}</label>
                <input id="of-until" type="date" name="until" value="{{ $filters['until'] }}" class="w-full rounded-btn border border-line-strong px-2 py-2 text-sm focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary">
            </div>
            <div>
                <label for="of-sort" class="mb-1 block text-sm font-medium">{{ __('account.orders.sort') }}</label>
                <select id="of-sort" name="sort" class="w-full rounded-btn border border-line-strong px-2 py-2 text-sm focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary">
                    <option value="desc" @selected($filters['sort'] === 'desc')>{{ __('account.orders.sort_desc') }}</option>
                    <option value="asc" @selected($filters['sort'] === 'asc')>{{ __('account.orders.sort_asc') }}</option>
                </select>
            </div>
            <div class="flex flex-wrap items-center gap-3 sm:col-span-2 lg:col-span-5">
                <x-account.button class="text-sm">{{ __('account.orders.apply') }}</x-account.button>
                <a href="{{ route('customer.orders') }}" class="text-sm text-primary underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary">{{ __('account.orders.reset') }}</a>
            </div>
        </form>
        </div>

        {{-- CX-01: export of the history (PDF / Excel) for a period; quick periods fill the dates with Alpine, the form also works by hand. --}}
        <section id="export" class="mt-6 rounded-card border border-line p-4" aria-labelledby="export-title"
                 x-data="{ from: @js(old('from', $exportPresets['month'])), until: @js(old('until', $exportToday)) }">
            <h2 id="export-title" class="text-lg font-semibold">{{ __('customer_export.form.title') }}</h2>
            <p class="mt-1 text-sm text-ink-mid">{{ __('customer_export.form.lead') }}</p>
            @error('export')
                <p class="mt-3 rounded-btn border border-danger-line bg-danger-wash px-3 py-2 text-sm text-danger-ink" role="alert" data-export-error>{{ $message }}</p>
            @enderror
            <form method="POST" action="{{ route('customer.exports.store') }}" class="mt-3 grid gap-4 sm:grid-cols-2">
                @csrf
                <div class="sm:col-span-2">
                    <span class="mb-1 block text-sm font-medium">{{ __('customer_export.form.quick') }}</span>
                    <div class="flex flex-wrap gap-2">
                        @foreach (['month', 'quarter', 'year'] as $preset)
                            <button type="button" class="rounded-btn border border-line-strong px-3 py-1 text-sm hover:bg-surface-2 focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary"
                                    x-on:click="from = @js($exportPresets[$preset]); until = @js($exportToday)">{{ __('customer_export.form.presets.'.$preset) }}</button>
                        @endforeach
                    </div>
                </div>
                <div>
                    <label for="ex-from" class="mb-1 block text-sm font-medium">{{ __('customer_export.form.from') }}</label>
                    <input id="ex-from" type="date" name="from" x-model="from" value="{{ old('from', $exportPresets['month']) }}" required class="w-full rounded-btn border border-line-strong px-2 py-2 text-sm focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary">
                </div>
                <div>
                    <label for="ex-until" class="mb-1 block text-sm font-medium">{{ __('customer_export.form.until') }}</label>
                    <input id="ex-until" type="date" name="until" x-model="until" value="{{ old('until', $exportToday) }}" required class="w-full rounded-btn border border-line-strong px-2 py-2 text-sm focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary">
                </div>
                <fieldset>
                    <legend class="mb-1 text-sm font-medium">{{ __('customer_export.form.format') }}</legend>
                    <label class="mr-4 inline-flex items-center gap-2 text-sm"><input type="radio" name="format" value="pdf" @checked(old('format', 'pdf') === 'pdf')> {{ __('customer_export.form.pdf') }}</label>
                    <label class="inline-flex items-center gap-2 text-sm"><input type="radio" name="format" value="xlsx" @checked(old('format') === 'xlsx')> {{ __('customer_export.form.xlsx') }}</label>
                </fieldset>
                <div class="flex items-end">
                    <label class="inline-flex items-center gap-2 text-sm"><input type="checkbox" name="with_items" value="1" @checked(old('with_items'))> {{ __('customer_export.form.with_items') }}</label>
                </div>
                <fieldset>
                    <legend class="mb-1 text-sm font-medium">{{ __('customer_export.form.types') }}</legend>
                    @foreach ($types as $value => $label)
                        <label class="mr-4 inline-flex items-center gap-2 text-sm"><input type="checkbox" name="types[]" value="{{ $value }}" @checked(in_array($value, (array) old('types', []), true))> {{ $label }}</label>
                    @endforeach
                </fieldset>
                <fieldset>
                    <legend class="mb-1 text-sm font-medium">{{ __('customer_export.form.statuses') }}</legend>
                    @foreach ($statuses as $value => $label)
                        <label class="mr-4 inline-flex items-center gap-2 text-sm"><input type="checkbox" name="statuses[]" value="{{ $value }}" @checked(in_array($value, (array) old('statuses', []), true))> {{ $label }}</label>
                    @endforeach
                </fieldset>
                <p class="text-xs text-muted sm:col-span-2">{{ __('customer_export.form.all_hint') }}</p>
                <div class="flex flex-wrap items-center gap-3 sm:col-span-2">
                    <x-account.button class="text-sm">{{ __('customer_export.form.submit') }}</x-account.button>
                    <a href="{{ route('customer.exports') }}" class="inline-flex min-h-11 items-center text-sm text-primary underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary">{{ __('customer_export.form.my_exports') }}</a>
                </div>
            </form>
        </section>

        @if ($orders->isEmpty())
            <p class="mt-6 rounded-card border border-line p-4 text-ink-mid" data-orders-empty>{{ __('account.orders.empty') }}</p>
        @else
            {{-- MB-09: a card per order on a phone (number, date, status badge, sum, «Открыть»); a plain list from 768 px. --}}
            <ul class="mt-6 space-y-3 md:space-y-0 md:divide-y md:divide-line md:rounded-card md:border md:border-line" data-orders-list>
                @foreach ($orders as $order)
                    <li class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 p-4 max-md:rounded-card max-md:border max-md:border-line" data-order data-order-card>
                        <div class="min-w-0">
                            <a href="{{ route('customer.orders.show', $order->uuid) }}" class="font-semibold text-primary underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary">{{ $order->type->label() }} {{ $order->number }}</a>
                            <p class="text-sm text-ink-mid">{{ $order->created_at?->format('d.m.Y H:i') }} · {{ __('account.orders.items_count', ['count' => $order->items_count]) }}</p>
                        </div>
                        <div class="text-right">
                            <p class="font-medium">{{ $order->total !== null ? \App\Support\Money::format(\App\Support\Money::fromRubles($order->total)) : __('account.orders.price_on_request') }}</p>
                            <p class="mt-1"><span class="inline-block rounded-full bg-surface-3 px-2.5 py-0.5 text-xs font-medium text-ink-soft" data-order-status-badge>{{ $order->status->label($order->type) }}</span></p>
                        </div>
                        <a href="{{ route('customer.orders.show', $order->uuid) }}" class="flex min-h-11 w-full items-center justify-center rounded-btn border border-primary px-4 py-2 text-sm font-medium text-primary hover:bg-surface-2 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary md:hidden" data-order-open aria-label="{{ __('mobile.cabinet.open') }}: {{ $order->type->label() }} {{ $order->number }}">{{ __('mobile.cabinet.open') }}</a>
                    </li>
                @endforeach
            </ul>
            {{ $orders->links('storefront.partials.pagination') }}
        @endif
    </div>
@endsection
