<div>
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
                        <li>{{ __('storefront_sales.cart.price_changed', ['name' => $line->product?->name, 'old' => \App\Support\Money::format($line->priceAtAdd), 'new' => \App\Support\Money::format($line->currentPrice)]) }}</li>
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
            <ul class="divide-y divide-slate-200 rounded-lg border border-slate-200" aria-label="{{ __('storefront_sales.cart.title') }}">
                @foreach ($summary->lines as $line)
                    @php($product = $line->product)
                    @php($pid = $line->item->product_id)
                    <li wire:key="line-{{ $pid }}" class="flex flex-wrap items-center gap-3 p-3 sm:flex-nowrap sm:gap-4 sm:p-4 {{ $line->unavailable ? 'bg-slate-50' : '' }}" data-cart-line>
                        @php($age = $product !== null ? app(\App\Services\Age\AgeRestrictionResolver::class)->forProduct($product) : null)
                        <div class="age-media h-20 w-20 flex-none overflow-hidden rounded bg-slate-50" @if ($age) data-age="{{ $age->value }}" @endif>
                            @if ($product?->mainImage)
                                <img src="{{ $product->mainImage->urlSmall() }}" alt="" width="80" height="80" loading="lazy" class="age-media-img h-full w-full object-contain">
                            @endif
                        </div>
                        <div class="min-w-0 flex-1 basis-40">
                            @if ($product !== null && ! $line->unavailable)
                                <a href="{{ route('product.show', $product->slug) }}" class="font-medium hover:text-primary focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary">{{ $product->name }}</a>
                            @else
                                <span class="font-medium text-slate-600">{{ $product?->name ?? __('storefront_sales.cart.unavailable') }}</span>
                            @endif
                            @if ($line->priceChanged && ! $line->unavailable)
                                <p class="mt-1 text-sm"><s class="text-slate-500">{{ \App\Support\Money::format($line->priceAtAdd) }}</s> <span class="font-semibold">{{ \App\Support\Money::format($line->currentPrice) }}</span></p>
                            @elseif (! $line->unavailable)
                                <p class="mt-1 text-sm text-slate-600">{{ \App\Support\Money::format($line->currentPrice) }}</p>
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
                            <p class="w-28 text-right font-semibold">{{ \App\Support\Money::format($line->lineTotal) }}</p>
                        @endunless
                        <button type="button" wire:click="remove({{ $pid }})" class="rounded p-2 text-slate-500 hover:bg-slate-100 hover:text-red-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary"
                                aria-label="{{ __('storefront_sales.cart.remove_label', ['name' => $product?->name ?? '']) }}">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 6l12 12M18 6L6 18"/></svg>
                        </button>
                    </li>
                @endforeach
            </ul>

            <aside class="h-fit rounded-lg border border-slate-200 p-4" aria-label="{{ __('storefront_sales.checkout.your_order') }}">
                <p class="flex justify-between text-lg font-semibold"><span>{{ __('storefront_sales.cart.items_total') }}</span> <span data-items-total>{{ \App\Support\Money::format($summary->itemsTotal) }}</span></p>
                <p class="mt-1 text-sm text-slate-500">{{ __('storefront_sales.cart.delivery_hint') }}</p>

                @if ($summary->minOrderMissing > 0)
                    <p class="mt-3 rounded border border-amber-300 bg-amber-50 p-2 text-sm text-amber-900" id="min-order-note" data-min-order>
                        {{ __('storefront_sales.cart.min_order', ['min' => \App\Support\Money::format($summary->minOrderAmount), 'missing' => \App\Support\Money::format($summary->minOrderMissing)]) }}
                    </p>
                @endif

                @if ($summary->canCheckout)
                    <a href="{{ route('checkout') }}" class="mt-4 block rounded bg-primary px-4 py-3 text-center font-medium text-white hover:opacity-90 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary" data-checkout>{{ __('storefront_sales.cart.checkout') }}</a>
                @else
                    <span aria-disabled="true" @if ($summary->minOrderMissing > 0) aria-describedby="min-order-note" @endif class="mt-4 block cursor-not-allowed rounded bg-slate-200 px-4 py-3 text-center font-medium text-slate-500" data-checkout-disabled>{{ __('storefront_sales.cart.checkout') }}</span>
                @endif

                <a href="{{ route('catalog.index') }}" class="mt-3 block text-center text-sm text-primary underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary">{{ __('storefront_sales.cart.continue') }}</a>
            </aside>
        </div>
    @endif
</div>
