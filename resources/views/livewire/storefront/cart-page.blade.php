<div>
    {{-- CA-13: result of «Повторить заказ». --}}
    @if (session('reorder_report'))
        <p role="status" class="mb-4 rounded-lg border border-emerald-300 bg-emerald-50 p-4 text-emerald-900" data-reorder-report>{{ session('reorder_report') }}</p>
    @endif

    {{-- SF-46: reasons of a cart changed during checkout. --}}
    @if ($changedReasons !== [])
        <div role="alert" class="mb-4 rounded-lg border border-amber-400 bg-amber-50 p-4 text-amber-900" data-cart-changed>
            <p class="font-semibold">{{ __('storefront_sales.cart.checkout_changed') }}</p>
            <p class="mt-1 text-sm">{{ __('sales.checkout.changed_intro') }}</p>
            <ul class="mt-2 list-disc space-y-1 pl-5 text-sm">
                @foreach ($changedReasons as $reason)
                    <li>{{ $reason }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- MR-07: a reservation is short of stock; the rows stay, the customer reduces them with one click. --}}
    @if ($shortLines !== [])
        <div role="alert" class="mb-4 rounded-lg border border-amber-400 bg-amber-50 p-4 text-amber-900" data-reserve-shortage>
            <p class="font-semibold">{{ __('storefront_sales.cart.shortage_title') }}</p>
            <ul class="mt-2 list-disc space-y-1 pl-5 text-sm">
                @foreach ($shortLines as $short)
                    <li>{{ __('storefront_sales.cart.shortage_line', ['name' => $short->product?->name.($short->variantLabel ? ' ('.$short->variantLabel.')' : ''), 'available' => $short->maxReserveQty, 'qty' => $short->qty]) }}</li>
                @endforeach
            </ul>
            <button type="button" wire:click="reduceShortages" data-reduce-shortage class="mt-3 rounded bg-amber-600 px-4 py-2 text-sm font-medium text-white hover:bg-amber-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-800">{{ __('storefront_sales.cart.reduce_to_available') }}</button>
        </div>
    @endif

    @if ($summary->isEmpty())
        <div class="rounded-lg border border-slate-200 p-8 text-center" data-cart-empty>
            <p class="text-lg text-slate-700">{{ __('storefront_sales.cart.empty') }}</p>
            <a href="{{ route('catalog.index') }}" class="mt-4 inline-block rounded bg-primary px-6 py-3 font-medium text-white hover:opacity-90 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">{{ __('storefront_sales.cart.to_catalog') }}</a>
        </div>
    @else
        {{-- SF-42: prices differ from those at the time of adding. --}}
        @php($priceChanges = array_values(array_filter($summary->lines, fn ($l) => $l->priceChanged && ! $l->unavailable)))
        @if ($priceChanges !== [])
            <div role="alert" class="mb-4 rounded-lg border border-amber-400 bg-amber-50 p-4 text-amber-900" data-price-changed>
                <p class="font-semibold">{{ __('storefront_sales.cart.changed_title') }}</p>
                <p class="mt-1 text-sm">{{ __('storefront_sales.cart.changed_text') }}</p>
                <ul class="mt-2 list-disc space-y-1 pl-5 text-sm">
                    @foreach ($priceChanges as $line)
                        <li>{{ __('storefront_sales.cart.price_changed', ['name' => $line->product?->name.($line->variantLabel ? ' ('.$line->variantLabel.')' : ''), 'old' => \App\Support\Money::format($line->priceAtAdd), 'new' => \App\Support\Money::format($line->currentPrice)]) }}</li>
                    @endforeach
                </ul>
                <button type="button" wire:click="acknowledge" class="mt-3 rounded bg-amber-600 px-4 py-2 text-sm font-medium text-white hover:bg-amber-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-800">{{ __('storefront_sales.cart.understood') }}</button>
            </div>
        @endif

        <div aria-live="assertive" role="alert">
            @error('cart')
                <p class="mb-4 rounded border border-red-300 bg-red-50 px-3 py-2 text-sm text-red-800">{{ $message }}</p>
            @enderror
        </div>

        <div class="grid gap-6 lg:grid-cols-[1fr_20rem]">
            @php($groups = $summary->hasReservation
                ? array_filter([
                    'order' => array_values(array_filter($summary->lines, fn ($l) => $l->target !== \App\Enums\CheckoutChoice::Reservation)),
                    'reservation' => array_values(array_filter($summary->lines, fn ($l) => $l->target === \App\Enums\CheckoutChoice::Reservation)),
                ])
                : ['all' => $summary->lines])
            <div class="space-y-4">
            @foreach ($groups as $groupKey => $groupLines)
            <section data-cart-block="{{ $groupKey }}">
            @if ($summary->hasReservation)
                {{-- MR-04: the blocks «Заказ» and «Резерв» of a mixed cart. --}}
                <h2 class="mb-2 text-lg font-semibold">{{ __('storefront_sales.cart.block_'.$groupKey) }}</h2>
            @endif
            <ul class="divide-y divide-slate-200 rounded-lg border border-slate-200" aria-label="{{ __('storefront_sales.cart.title') }}">
                @foreach ($groupLines as $line)
                    @php($product = $line->product)
                    @php($pid = $line->item->product_id.'_'.$line->item->variant_key)
                    <li wire:key="line-{{ $pid }}" class="flex flex-wrap items-center gap-3 p-3 sm:flex-nowrap sm:gap-4 sm:p-4 {{ $line->unavailable ? 'bg-slate-50' : '' }}" data-cart-line>
                        @php($age = $product !== null ? app(\App\Services\Age\AgeRestrictionResolver::class)->forProduct($product) : null)
                        <div class="age-media h-20 w-20 flex-none overflow-hidden rounded bg-slate-50" @if ($age) data-age="{{ $age->value }}" @endif>
                            @if ($product?->mainImage)
                                <img src="{{ $product->mainImage->urlSmall() }}" alt="" width="80" height="80" loading="lazy" class="age-media-img h-full w-full object-contain">
                            @endif
                        </div>
                        <div class="min-w-0 flex-1 basis-40">
                            @if ($product !== null && ! $line->unavailable)
                                <a href="{{ route('product.show', array_filter(['slug' => $product->slug, 'variant' => $line->variant?->id])) }}" class="font-medium hover:text-primary focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary">{{ $product->name }}</a>
                            @else
                                <span class="font-medium text-slate-600">{{ $product?->name ?? __('storefront_sales.cart.unavailable') }}</span>
                            @endif
                            @if ($line->variantLabel)
                                <p class="mt-0.5 text-sm text-slate-600" data-variant-label>{{ $line->variantLabel }}</p>
                            @endif
                            @if ($line->kind === \App\Enums\CartLineKind::Reservation)
                                <p class="mt-1"><span class="rounded bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-900" data-line-kind="reservation">{{ __('storefront_sales.cart.kind_reservation') }}</span></p>
                            @elseif ($line->kind === \App\Enums\CartLineKind::Flexible)
                                <p class="mt-1"><span class="rounded bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-700" data-line-kind="flexible">{{ __('storefront_sales.cart.kind_flexible') }}</span></p>
                            @endif
                            @if ($line->priceChanged && ! $line->unavailable)
                                <p class="mt-1 text-sm"><s class="text-slate-500">{{ \App\Support\Money::format($line->priceAtAdd) }}</s> <span class="font-semibold">{{ \App\Support\Money::format($line->currentPrice) }}</span></p>
                            @elseif (! $line->unavailable)
                                <p class="mt-1 text-sm text-slate-600">{{ \App\Support\Money::format($line->currentPrice) }}</p>
                            @endif
                            @if ($line->tierHint && ! $line->unavailable)
                                {{-- GP-11: the next quantity tier is close. --}}
                                <p class="mt-1 text-sm text-emerald-800" data-tier-hint>{{ __('group_prices.cart.tier_hint', ['missing' => $line->tierHint->missingQty, 'price' => \App\Support\Money::format($line->tierHint->price), 'unit' => $product?->unit]) }}</p>
                            @endif
                            @if ($line->issue)
                                <p class="mt-1 text-sm text-red-700">{{ $line->issue }}</p>
                            @endif
                        </div>
                        @unless ($line->unavailable)
                            <div>
                                <label for="cart-qty-{{ $pid }}" class="sr-only">{{ __('storefront_sales.add.qty_of', ['name' => $product?->name]) }}</label>
                                <input id="cart-qty-{{ $pid }}" type="number" inputmode="numeric" min="1" @if ($line->maxQty !== null) max="{{ $line->maxQty }}" @endif
                                       wire:model.live.debounce.600ms="qtys.{{ $pid }}"
                                       class="w-20 rounded border border-slate-300 px-2 py-2 text-center focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary">
                            </div>
                            @php($lineDiscount = $promo['applied'] ? $promo['result']->discountFor($line->item->id) : 0)
                            <p class="w-28 text-right font-semibold" data-line-total>
                                @if ($lineDiscount > 0)
                                    {{-- PM-13: the price of the row before and after the promo code. --}}
                                    <s class="block text-sm font-normal text-slate-500" data-line-old>{{ \App\Support\Money::format($line->lineTotal) }}</s>
                                    <span data-line-new>{{ \App\Support\Money::format($line->lineTotal - $lineDiscount) }}</span>
                                @else
                                    {{ \App\Support\Money::format($line->lineTotal) }}
                                @endif
                            </p>
                        @endunless
                        <button type="button" wire:click="remove({{ $line->item->product_id }}, {{ $line->item->variant_key }})" class="rounded p-2 text-slate-500 hover:bg-slate-100 hover:text-red-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary"
                                aria-label="{{ __('storefront_sales.cart.remove_label', ['name' => $product?->name ?? '']) }}">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 6l12 12M18 6L6 18"/></svg>
                        </button>
                    </li>
                @endforeach
            </ul>
            </section>
            @endforeach
            </div>

            <aside class="h-fit rounded-lg border border-slate-200 p-4" aria-label="{{ __('storefront_sales.checkout.your_order') }}">
                @php($promoShown = $promo['applied'] && ($promo['discount'] > 0 || $promo['freeDelivery']))
                <p class="flex justify-between {{ $promoShown ? 'text-sm' : 'text-lg font-semibold' }}"><span>{{ __($summary->hasReservation ? 'storefront_sales.cart.order_total' : 'storefront_sales.cart.items_total') }}</span> <span data-items-total>{{ \App\Support\Money::format($summary->itemsTotal) }}</span></p>
                @if ($summary->hasReservation)
                    <p class="mt-1 flex justify-between text-sm" data-reservation-total><span>{{ __('storefront_sales.cart.reservation_total') }}</span> <span>{{ \App\Support\Money::format($summary->reservationTotal) }}</span></p>
                @endif
                @if ($promoShown)
                    @if ($promo['discount'] > 0)
                        <p class="mt-1 flex justify-between text-sm text-emerald-800" data-promo-discount><span>{{ __('promo.box.discount', ['code' => $promo['code']]) }}</span> <span>−{{ \App\Support\Money::format($promo['discount']) }}</span></p>
                    @endif
                    @if ($promo['freeDelivery'])
                        <p class="mt-1 flex justify-between text-sm text-emerald-800" data-promo-free-delivery><span>{{ __('storefront_sales.checkout.delivery_price') }}</span> <span>{{ __('promo.box.free_delivery') }}</span></p>
                    @endif
                    <p class="mt-2 flex justify-between border-t border-slate-200 pt-2 text-lg font-semibold"><span>{{ __('promo.box.total') }}</span> <span data-promo-total>{{ \App\Support\Money::format($summary->itemsTotal - $promo['discount']) }}</span></p>
                @endif
                @unless ($promo['freeDelivery'])
                    <p class="mt-1 text-sm text-slate-500">{{ __('storefront_sales.cart.delivery_hint') }}</p>
                @endunless

                <div class="mt-3">
                    @include('livewire.storefront.partials.promo-box')
                </div>

                @if ($summary->minOrderMissing > 0)
                    <p class="mt-3 rounded border border-amber-300 bg-amber-50 p-2 text-sm text-amber-900" id="min-order-note" data-min-order>
                        {{ __('storefront_sales.cart.min_order', ['min' => \App\Support\Money::format($summary->minOrderAmount), 'missing' => \App\Support\Money::format($summary->minOrderMissing)]) }}
                    </p>
                @endif

                @if ($summary->canProceed() && $mustSignIn)
                    <p class="mt-4 rounded border border-amber-300 bg-amber-50 p-2 text-sm text-amber-900" data-login-required>{{ __('account.errors.login_required_to_order') }}</p>
                    <a href="{{ route('customer.login', ['back' => 'checkout']) }}" class="mt-3 block rounded bg-primary px-4 py-3 text-center font-medium text-white hover:opacity-90 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary" data-checkout-login>{{ __('account.actions.login_to_order') }}</a>
                @elseif ($summary->canProceed())
                    <a href="{{ route('checkout') }}" class="mt-4 block rounded bg-primary px-4 py-3 text-center font-medium text-white hover:opacity-90 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary" data-checkout>{{ $checkoutLabel }}</a>
                @else
                    <span aria-disabled="true" @if ($summary->minOrderMissing > 0) aria-describedby="min-order-note" @endif class="mt-4 block cursor-not-allowed rounded bg-slate-200 px-4 py-3 text-center font-medium text-slate-500" data-checkout-disabled>{{ $checkoutLabel }}</span>
                @endif

                <a href="{{ route('catalog.index') }}" class="mt-3 block text-center text-sm text-primary underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary">{{ __('storefront_sales.cart.continue') }}</a>
            </aside>
        </div>
    @endif
</div>
