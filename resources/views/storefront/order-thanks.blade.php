@extends('storefront.layouts.app')

@section('title', __('storefront_sales.thanks.title'))

@push('head')
    <meta name="robots" content="noindex, nofollow">
@endpush

@section('content')
    @php($type = $order->type->value)
    <div class="mx-auto max-w-2xl">
        <h1 class="text-2xl font-bold md:text-3xl" data-thanks-heading>{{ count($orders) > 1 ? __('storefront_sales.thanks.requests_title', ['count' => count($orders)]) : __('storefront_sales.thanks.'.$type, ['number' => $order->number]) }}</h1>
        @if (count($orders) === 1)
            <p class="mt-1 text-sm text-muted" data-order-type>{{ $order->type->label() }} {{ $order->number }}</p>
        @endif

        <p class="mt-4 whitespace-pre-line text-ink-soft">{{ $thanksText }}</p>
        @include('storefront.partials.tg-thanks-note')

        @if (count($orders) > 1)
            {{-- MR-11: both requests of one checkout. --}}
            <p class="mt-2 text-sm text-ink-soft" data-two-numbers>{{ __('storefront_sales.thanks.two_numbers', ['numbers' => collect($orders)->pluck('number')->implode(', ')]) }}</p>
        @endif

        @foreach ($orders as $placed)
            <div @if (count($orders) > 1) class="mt-8 border-t border-line pt-4" data-thanks-request="{{ $placed->type->value }}" @endif>
                @if (count($orders) > 1)
                    <h2 class="text-xl font-semibold">{{ __('storefront_sales.thanks.request_heading', ['type' => $placed->type->label(), 'number' => $placed->number]) }}</h2>
                @endif
                @include('storefront.partials.thanks-request', ['order' => $placed])
            </div>
        @endforeach

        {{-- TG-08: follow the status of these requests in Telegram. --}}
        @if ($telegramLink)
            <section class="mt-6 rounded-card border border-line p-4" aria-labelledby="thanks-telegram" data-telegram-follow>
                <h2 id="thanks-telegram" class="font-body font-semibold">{{ __('telegram.order_link.button') }}</h2>
                <p class="mt-1 text-sm text-ink-mid">{{ __('telegram.order_link.hint') }}</p>
                <a href="{{ $telegramLink }}" rel="noopener" class="mt-3 inline-block rounded-btn border border-primary px-4 py-2 text-sm font-medium text-primary hover:bg-surface-2 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">{{ __('telegram.order_link.button') }}</a>
            </section>
        @endif

        {{-- CA-27: a guest is offered a cabinet; the account is never created automatically. --}}
        @if ($offerAccount)
            <section class="mt-6 rounded-card border border-line p-4" aria-labelledby="thanks-account" data-create-account>
                <h2 id="thanks-account" class="font-body font-semibold">{{ __('account.thanks.title') }}</h2>
                <p class="mt-1 text-sm text-ink-mid">{{ __('account.thanks.text') }}</p>
                <a href="{{ route('customer.register') }}" class="mt-3 inline-block rounded-btn border border-primary px-4 py-2 text-sm font-medium text-primary hover:bg-surface-2 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">{{ __('account.thanks.create') }}</a>
            </section>
        @endif

        <a href="{{ route('catalog.index') }}" class="mt-6 inline-block rounded-btn bg-primary px-6 py-3 font-medium text-on-primary hover:opacity-90 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">{{ __('storefront_sales.thanks.to_catalog') }}</a>
    </div>
@endsection
