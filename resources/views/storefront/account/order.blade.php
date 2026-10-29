@extends('storefront.layouts.app')

@section('content')
    <div class="mx-auto w-full max-w-3xl py-6">
        <p class="text-sm"><a href="{{ route('customer.orders') }}" class="text-primary underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary">{{ __('account.orders.title') }}</a></p>
        <h1 class="mt-2 text-2xl font-bold md:text-3xl">{{ $order->type->label() }} {{ $order->number }}</h1>
        <p class="mt-1 text-sm text-slate-600">{{ __('account.orders.created', ['date' => $order->created_at?->format('d.m.Y H:i')]) }}</p>
        <div class="mt-4">
            <x-account.errors />
        </div>

        <dl class="grid gap-x-8 gap-y-2 text-sm sm:grid-cols-[max-content_1fr]">
            <dt class="text-slate-500">{{ __('account.orders.status') }}</dt>
            <dd class="font-medium" data-order-status>{{ $order->status->label($order->type) }}</dd>
            @if ($order->customer_group_name)
                <dt class="text-slate-500">{{ __('account.orders.group') }}</dt>
                <dd class="font-medium" data-order-group>{{ $order->customer_group_name }}</dd>
            @endif
            @if ($order->type === \App\Enums\OrderType::Reservation && $order->reserved_until)
                <dt class="text-slate-500">{{ __('account.orders.reserved_until') }}</dt>
                <dd class="font-medium" data-reserved-until>{{ $order->reserved_until->format('d.m.Y H:i') }}</dd>
            @endif
            @if ($order->delivery_method)
                <dt class="text-slate-500">{{ __('account.orders.delivery') }}</dt>
                <dd class="font-medium">{{ $order->delivery_method }}</dd>
            @endif
            @if ($order->delivery_address)
                <dt class="text-slate-500">{{ __('account.orders.address') }}</dt>
                <dd class="font-medium">{{ $order->delivery_address }}</dd>
            @endif
            @if ($order->payment_method)
                <dt class="text-slate-500">{{ __('account.orders.payment') }}</dt>
                <dd class="font-medium">{{ $order->payment_method }}</dd>
            @endif
        </dl>

        <section class="mt-6" aria-labelledby="order-items">
            <h2 id="order-items" class="mb-2 text-lg font-semibold">{{ __('account.orders.items') }}</h2>
            <ul class="divide-y divide-slate-200 rounded-lg border border-slate-200">
                @foreach ($order->items as $item)
                    <li class="flex flex-wrap items-baseline justify-between gap-2 p-3 text-sm">
                        <span>{{ $item->name }} <span class="text-slate-500">× {{ $item->qty }} {{ $item->unit }}</span></span>
                        <span class="font-medium">
                            @if ($item->price !== null)
                                {{ \App\Support\Money::format(\App\Support\Money::fromRubles($item->price)) }} → {{ \App\Support\Money::format(\App\Support\Money::fromRubles((string) $item->total)) }}
                            @else
                                {{ __('account.orders.price_on_request') }}
                            @endif
                        </span>
                    </li>
                @endforeach
            </ul>
            <dl class="mt-3 space-y-1 text-sm">
                @if ($order->type === \App\Enums\OrderType::Order)
                    <div class="flex justify-between gap-3"><dt class="text-slate-600">{{ __('account.orders.delivery_price') }}</dt><dd>{{ \App\Support\Money::format(\App\Support\Money::fromRubles($order->delivery_price)) }}</dd></div>
                @endif
                <div class="flex justify-between gap-3 border-t border-slate-200 pt-2 text-lg font-semibold">
                    <dt>{{ __('account.orders.total') }}</dt>
                    <dd data-total>{{ $order->total !== null ? \App\Support\Money::format(\App\Support\Money::fromRubles($order->total)) : __('account.orders.price_on_request') }}</dd>
                </div>
            </dl>
        </section>

        {{-- Status history: the dates and statuses only; comments of the staff are internal and never shown. --}}
        <section class="mt-6" aria-labelledby="order-history">
            <h2 id="order-history" class="mb-2 text-lg font-semibold">{{ __('account.orders.history') }}</h2>
            <ol class="space-y-1 text-sm" data-status-history>
                @foreach ($order->statusHistory as $entry)
                    <li>
                        <span class="text-slate-500">{{ $entry->created_at->format('d.m.Y H:i') }}</span>
                        — {{ $entry->to_status->label($order->type) }}
                    </li>
                @endforeach
            </ol>
        </section>

        @if ($canRepeat)
            <form method="POST" action="{{ route('customer.orders.repeat', $order->uuid) }}" class="mt-8">
                @csrf
                <x-account.button>{{ __('account.orders.repeat') }}</x-account.button>
            </form>
        @endif
    </div>
@endsection
