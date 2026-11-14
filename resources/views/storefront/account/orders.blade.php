@extends('storefront.layouts.app')

@section('content')
    <div class="mx-auto w-full max-w-4xl py-6">
        <p class="text-sm"><a href="{{ route('account') }}" class="text-primary underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary">{{ __('account.index.title') }}</a></p>
        <h1 class="mt-2 text-2xl font-bold md:text-3xl">{{ __('account.orders.title') }}</h1>
        <div class="mt-4">
            <x-account.errors />
        </div>

        {{-- CA-11: filters by type, status and period; sort by date. A plain GET form, it works without JavaScript. --}}
        <form method="GET" action="{{ route('customer.orders') }}" class="grid gap-3 rounded-lg border border-slate-200 p-4 sm:grid-cols-2 lg:grid-cols-5" aria-label="{{ __('account.orders.filters') }}">
            <div>
                <label for="of-type" class="mb-1 block text-sm font-medium">{{ __('account.orders.type') }}</label>
                <select id="of-type" name="type" class="w-full rounded border border-slate-300 px-2 py-2 text-sm focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary">
                    <option value="">{{ __('account.orders.any') }}</option>
                    @foreach ($types as $value => $label)
                        <option value="{{ $value }}" @selected($filters['type'] === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="of-status" class="mb-1 block text-sm font-medium">{{ __('account.orders.status') }}</label>
                <select id="of-status" name="status" class="w-full rounded border border-slate-300 px-2 py-2 text-sm focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary">
                    <option value="">{{ __('account.orders.any') }}</option>
                    @foreach ($statuses as $value => $label)
                        <option value="{{ $value }}" @selected($filters['status'] === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="of-from" class="mb-1 block text-sm font-medium">{{ __('account.orders.from') }}</label>
                <input id="of-from" type="date" name="from" value="{{ $filters['from'] }}" class="w-full rounded border border-slate-300 px-2 py-2 text-sm focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary">
            </div>
            <div>
                <label for="of-until" class="mb-1 block text-sm font-medium">{{ __('account.orders.until') }}</label>
                <input id="of-until" type="date" name="until" value="{{ $filters['until'] }}" class="w-full rounded border border-slate-300 px-2 py-2 text-sm focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary">
            </div>
            <div>
                <label for="of-sort" class="mb-1 block text-sm font-medium">{{ __('account.orders.sort') }}</label>
                <select id="of-sort" name="sort" class="w-full rounded border border-slate-300 px-2 py-2 text-sm focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary">
                    <option value="desc" @selected($filters['sort'] === 'desc')>{{ __('account.orders.sort_desc') }}</option>
                    <option value="asc" @selected($filters['sort'] === 'asc')>{{ __('account.orders.sort_asc') }}</option>
                </select>
            </div>
            <div class="flex flex-wrap items-center gap-3 sm:col-span-2 lg:col-span-5">
                <x-account.button class="text-sm">{{ __('account.orders.apply') }}</x-account.button>
                <a href="{{ route('customer.orders') }}" class="text-sm text-primary underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary">{{ __('account.orders.reset') }}</a>
            </div>
        </form>

        {{-- CX-01: export of the history (PDF / Excel) for a period; quick periods fill the dates with Alpine, the form also works by hand. --}}
        <section id="export" class="mt-6 rounded-lg border border-slate-200 p-4" aria-labelledby="export-title"
                 x-data="{ from: @js(old('from', $exportPresets['month'])), until: @js(old('until', $exportToday)) }">
            <h2 id="export-title" class="text-lg font-semibold">{{ __('customer_export.form.title') }}</h2>
            <p class="mt-1 text-sm text-slate-600">{{ __('customer_export.form.lead') }}</p>
            @error('export')
                <p class="mt-3 rounded border border-red-300 bg-red-50 px-3 py-2 text-sm text-red-800" role="alert" data-export-error>{{ $message }}</p>
            @enderror
            <form method="POST" action="{{ route('customer.exports.store') }}" class="mt-3 grid gap-4 sm:grid-cols-2">
                @csrf
                <div class="sm:col-span-2">
                    <span class="mb-1 block text-sm font-medium">{{ __('customer_export.form.quick') }}</span>
                    <div class="flex flex-wrap gap-2">
                        @foreach (['month', 'quarter', 'year'] as $preset)
                            <button type="button" class="rounded border border-slate-300 px-3 py-1 text-sm hover:bg-slate-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary"
                                    x-on:click="from = @js($exportPresets[$preset]); until = @js($exportToday)">{{ __('customer_export.form.presets.'.$preset) }}</button>
                        @endforeach
                    </div>
                </div>
                <div>
                    <label for="ex-from" class="mb-1 block text-sm font-medium">{{ __('customer_export.form.from') }}</label>
                    <input id="ex-from" type="date" name="from" x-model="from" value="{{ old('from', $exportPresets['month']) }}" required class="w-full rounded border border-slate-300 px-2 py-2 text-sm focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary">
                </div>
                <div>
                    <label for="ex-until" class="mb-1 block text-sm font-medium">{{ __('customer_export.form.until') }}</label>
                    <input id="ex-until" type="date" name="until" x-model="until" value="{{ old('until', $exportToday) }}" required class="w-full rounded border border-slate-300 px-2 py-2 text-sm focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary">
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
                <p class="text-xs text-slate-500 sm:col-span-2">{{ __('customer_export.form.all_hint') }}</p>
                <div class="flex flex-wrap items-center gap-3 sm:col-span-2">
                    <x-account.button class="text-sm">{{ __('customer_export.form.submit') }}</x-account.button>
                    <a href="{{ route('customer.exports') }}" class="text-sm text-primary underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary">{{ __('customer_export.form.my_exports') }}</a>
                </div>
            </form>
        </section>

        @if ($orders->isEmpty())
            <p class="mt-6 rounded-lg border border-slate-200 p-4 text-slate-600" data-orders-empty>{{ __('account.orders.empty') }}</p>
        @else
            <ul class="mt-6 divide-y divide-slate-200 rounded-lg border border-slate-200">
                @foreach ($orders as $order)
                    <li class="flex flex-wrap items-baseline justify-between gap-2 p-4" data-order>
                        <div>
                            <a href="{{ route('customer.orders.show', $order->uuid) }}" class="font-semibold text-primary underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary">{{ $order->type->label() }} {{ $order->number }}</a>
                            <p class="text-sm text-slate-600">{{ $order->created_at?->format('d.m.Y H:i') }} · {{ __('account.orders.items_count', ['count' => $order->items_count]) }}</p>
                        </div>
                        <div class="text-right">
                            <p class="font-medium">{{ $order->total !== null ? \App\Support\Money::format(\App\Support\Money::fromRubles($order->total)) : __('account.orders.price_on_request') }}</p>
                            <p class="text-sm text-slate-600">{{ $order->status->label($order->type) }}</p>
                        </div>
                    </li>
                @endforeach
            </ul>
            {{ $orders->links('storefront.partials.pagination') }}
        @endif
    </div>
@endsection
