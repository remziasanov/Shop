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
