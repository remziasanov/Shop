        {{-- SF-52: reservation term. --}}
        @if ($order->type === \App\Enums\OrderType::Reservation && $order->reserved_until)
            <p class="mt-4 rounded-card border border-success-line bg-success-wash p-3 font-medium text-success-deep" data-reserved-until>
                {{ __('storefront_sales.thanks.reserved_until', ['date' => $order->reserved_until->format('d.m H:i')]) }}
            </p>
            @if ($order->desired_date)
                <p class="mt-2 text-sm text-ink-mid">{{ __('storefront_sales.thanks.desired_date', ['date' => $order->desired_date->format('d.m.Y')]) }}</p>
            @endif
        @endif

        <section class="mt-6" aria-labelledby="thanks-items-{{ $order->id }}">
            <h2 id="thanks-items-{{ $order->id }}" class="mb-2 text-lg font-semibold">{{ __('storefront_sales.thanks.items') }}</h2>
            <ul class="divide-y divide-line rounded-card border border-line">
                @foreach ($order->items as $item)
                    <li class="flex flex-wrap items-baseline justify-between gap-2 p-3 text-sm">
                        <span>{{ $item->displayName() }} <span class="text-muted">× {{ $item->qty }} {{ $item->unit }}</span></span>
                        <span class="font-medium">{{ $item->total !== null ? \App\Support\Money::format(\App\Support\Money::fromRubles($item->total)) : __('storefront_sales.thanks.price_on_request') }}</span>
                    </li>
                @endforeach
            </ul>
        </section>

        <dl class="mt-4 space-y-1 text-sm">
            @if ($order->delivery_method)
                <div class="flex justify-between gap-3"><dt class="text-ink-mid">{{ __('storefront_sales.thanks.delivery') }}</dt><dd>{{ $order->delivery_method }}</dd></div>
            @endif
            @if ($order->delivery_address)
                <div class="flex justify-between gap-3"><dt class="text-ink-mid">{{ __('storefront_sales.thanks.address') }}</dt><dd class="text-right">{{ $order->delivery_address }}</dd></div>
            @endif
            @if ($order->payment_method)
                <div class="flex justify-between gap-3"><dt class="text-ink-mid">{{ __('storefront_sales.thanks.payment') }}</dt><dd>{{ $order->payment_method }}</dd></div>
            @endif
            @if ($order->type === \App\Enums\OrderType::Order)
                <div class="flex justify-between gap-3"><dt class="text-ink-mid">{{ __('storefront_sales.thanks.delivery_price') }}</dt><dd>{{ \App\Support\Money::format(\App\Support\Money::fromRubles($order->delivery_price)) }}</dd></div>
            @endif
            @foreach (\App\Support\OrderDiscountLines::for($order) as $discountLine)
                <div class="flex justify-between gap-3 text-success-ink" data-discount-line><dt>{{ $discountLine['label'] }}</dt><dd>{{ $discountLine['value'] }}</dd></div>
            @endforeach
            <div class="flex justify-between gap-3 border-t border-line pt-2 text-lg font-semibold">
                <dt>{{ __('storefront_sales.thanks.total') }}</dt>
                <dd data-total>{{ $order->total !== null ? \App\Support\Money::format(\App\Support\Money::fromRubles($order->total)) : __('storefront_sales.thanks.price_on_request') }}</dd>
            </div>
        </dl>

