@extends('storefront.layouts.app')

@section('title', __('storefront_sales.thanks.title'))

@push('head')
    <meta name="robots" content="noindex, nofollow">
@endpush

@section('content')
    @php($type = $order->type->value)
    <div class="mx-auto max-w-2xl">
        <h1 class="text-2xl font-bold md:text-3xl" data-thanks-heading>{{ __('storefront_sales.thanks.'.$type, ['number' => $order->number]) }}</h1>
        <p class="mt-1 text-sm text-slate-500" data-order-type>{{ $order->type->label() }} {{ $order->number }}</p>

        <p class="mt-4 whitespace-pre-line text-slate-700">{{ $thanksText }}</p>

        {{-- SF-52: reservation term. --}}
        @if ($order->type === \App\Enums\OrderType::Reservation && $order->reserved_until)
            <p class="mt-4 rounded-lg border border-emerald-300 bg-emerald-50 p-3 font-medium text-emerald-900" data-reserved-until>
                {{ __('storefront_sales.thanks.reserved_until', ['date' => $order->reserved_until->format('d.m H:i')]) }}
            </p>
            @if ($order->desired_date)
                <p class="mt-2 text-sm text-slate-600">{{ __('storefront_sales.thanks.desired_date', ['date' => $order->desired_date->format('d.m.Y')]) }}</p>
            @endif
        @endif

        <section class="mt-6" aria-labelledby="thanks-items">
            <h2 id="thanks-items" class="mb-2 text-lg font-semibold">{{ __('storefront_sales.thanks.items') }}</h2>
            <ul class="divide-y divide-slate-200 rounded-lg border border-slate-200">
                @foreach ($order->items as $item)
                    <li class="flex flex-wrap items-baseline justify-between gap-2 p-3 text-sm">
                        <span>{{ $item->name }} <span class="text-slate-500">× {{ $item->qty }} {{ $item->unit }}</span></span>
                        <span class="font-medium">{{ $item->total !== null ? \App\Support\Money::format(\App\Support\Money::fromRubles($item->total)) : __('storefront_sales.thanks.price_on_request') }}</span>
                    </li>
                @endforeach
            </ul>
        </section>

        <dl class="mt-4 space-y-1 text-sm">
            @if ($order->delivery_method)
                <div class="flex justify-between gap-3"><dt class="text-slate-600">{{ __('storefront_sales.thanks.delivery') }}</dt><dd>{{ $order->delivery_method }}</dd></div>
            @endif
            @if ($order->delivery_address)
                <div class="flex justify-between gap-3"><dt class="text-slate-600">{{ __('storefront_sales.thanks.address') }}</dt><dd class="text-right">{{ $order->delivery_address }}</dd></div>
            @endif
            @if ($order->payment_method)
                <div class="flex justify-between gap-3"><dt class="text-slate-600">{{ __('storefront_sales.thanks.payment') }}</dt><dd>{{ $order->payment_method }}</dd></div>
            @endif
            @if ($order->type === \App\Enums\OrderType::Order)
                <div class="flex justify-between gap-3"><dt class="text-slate-600">{{ __('storefront_sales.thanks.delivery_price') }}</dt><dd>{{ \App\Support\Money::format(\App\Support\Money::fromRubles($order->delivery_price)) }}</dd></div>
            @endif
            <div class="flex justify-between gap-3 border-t border-slate-200 pt-2 text-lg font-semibold">
                <dt>{{ __('storefront_sales.thanks.total') }}</dt>
                <dd data-total>{{ $order->total !== null ? \App\Support\Money::format(\App\Support\Money::fromRubles($order->total)) : __('storefront_sales.thanks.price_on_request') }}</dd>
            </div>
        </dl>

        {{-- CA-27: a guest is offered a cabinet; the account is never created automatically. --}}
        @if ($offerAccount)
            <section class="mt-6 rounded-lg border border-slate-200 p-4" aria-labelledby="thanks-account" data-create-account>
                <h2 id="thanks-account" class="font-semibold">{{ __('account.thanks.title') }}</h2>
                <p class="mt-1 text-sm text-slate-600">{{ __('account.thanks.text') }}</p>
                <a href="{{ route('customer.register', ['email' => $order->customer_email]) }}" class="mt-3 inline-block rounded border border-primary px-4 py-2 text-sm font-medium text-primary hover:bg-slate-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">{{ __('account.thanks.create') }}</a>
            </section>
        @endif

        <a href="{{ route('catalog.index') }}" class="mt-6 inline-block rounded bg-primary px-6 py-3 font-medium text-white hover:opacity-90 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">{{ __('storefront_sales.thanks.to_catalog') }}</a>
    </div>
@endsection
