{{-- MB-04: sticky bar of the product page on a phone: the final price and the main button of the sales mode. The button presses the main button of the buy block ([data-buy]: cart, reservation or inquiry), so the quantity and the forms stay in one place; with variants and none selected it scrolls to the picker and highlights it. Showcase: «Позвонить» (tel:). Inside the x-data of the page (variantPicker / tierPrice), the bar has its own `stickyBuy`. $view: ProductView, $picker: VariantPickerData|null. --}}
@php
    $showcase = $view->mode === \App\Enums\SalesMode::Showcase;
    $callPhone = $showcase && ! empty($shopPhones) ? $shopPhones[0] : null;
    $firstAction = collect($view->actions)->first(fn ($action) => ! $action['disabled'] && in_array($action['key'], ['cart', 'reservation', 'inquiry'], true));
    $initialLabel = $firstAction['label'] ?? ($picker !== null ? __('storefront.actions.cart') : __('mobile.product.unavailable'));
@endphp
<div class="shop-sticky-bar md:hidden" x-cloak x-data="stickyBuy" x-effect="{{ $picker !== null ? 'current;' : '' }} $nextTick(() => refresh())" data-sticky-buy>
    <div class="mx-auto flex w-full max-w-7xl items-center gap-3">
        <div class="min-w-0 flex-1" data-sticky-price>
            @if ($view->priceOnRequest)
                <p class="truncate text-base font-bold leading-tight">{{ __('storefront.product.price_on_request') }}</p>
            @elseif ($picker !== null)
                <p class="truncate text-lg font-bold leading-tight" x-text="current ? current.p : ''">{{ $view->price }}</p>
            @else
                <p class="truncate text-lg font-bold leading-tight">{{ $view->price }}</p>
            @endif
            @unless ($view->priceOnRequest)
                <p class="truncate text-xs text-muted">{{ __('storefront.product.per_unit', ['unit' => $product->unit]) }}</p>
            @endunless
        </div>

        @if ($showcase)
            @if ($callPhone)
                <a href="tel:{{ preg_replace('/[^\d+]/', '', $callPhone) }}" class="flex min-h-11 flex-none items-center justify-center rounded-btn bg-primary px-6 py-2 font-medium text-on-primary hover:opacity-90 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary" data-sticky-action="call">{{ __('mobile.product.call') }}</a>
            @endif
        @else
            <button type="button" @click="press({{ $picker !== null ? 'current' : 'null' }}, {{ $picker !== null ? 'true' : 'false' }})"
                    :disabled="kind === null && ! {{ $picker !== null ? '(! current)' : 'false' }}"
                    class="flex min-h-11 max-w-[60%] flex-none items-center justify-center rounded-btn bg-primary px-5 py-2 font-medium text-on-primary hover:opacity-90 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:cursor-not-allowed disabled:bg-line disabled:text-muted" data-sticky-action="buy">
                <span class="truncate" x-text="label !== '' ? label : @js(__('mobile.product.unavailable'))">{{ $initialLabel }}</span>
            </button>
        @endif
    </div>
</div>
