<div class="flex flex-col gap-2" data-add-to-cart>
    @if ($product !== null && $actions !== null && $product->has_variants)
        {{-- VR-09: the variant picker around this component (Alpine `variantPicker`) decides what is shown; the selected id goes to the server with the next click, which checks it again. --}}
        <div x-effect="$wire.variantId = current ? current.id : 0" x-cloak x-show="current && current.can.cart" class="flex flex-wrap items-end gap-2">
            @if ($showQty)
                <div>
                    <label for="qty-{{ $productId }}" class="mb-1 block text-sm font-medium">{{ __('storefront_sales.add.qty') }}</label>
                    <input id="qty-{{ $productId }}" type="number" inputmode="numeric" min="1" :max="current && current.max !== null ? current.max : null"
                           wire:model="qty" wire:keydown.enter="add"
                           class="w-24 rounded-btn border border-line-strong px-3 py-3 focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary">
                </div>
            @endif
            <button type="button" wire:click="add" wire:loading.attr="disabled" data-action="cart"
                    class="rounded-btn bg-primary px-6 py-3 font-medium text-on-primary hover:opacity-90 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:opacity-60">
                <span x-text="current ? current.cartLabel : ''">{{ $cartLabel }}</span><span class="sr-only">: {{ $product->name }}</span>
            </button>
        </div>
        <button type="button" disabled x-cloak x-show="current && current.can.out" data-action="unavailable" class="cursor-not-allowed rounded-btn bg-line px-6 py-3 font-medium text-muted">{{ __('sales.actions.out_of_stock') }}</button>
    @elseif ($product !== null && $actions !== null)
        @if ($kind !== null)
            <div class="flex flex-wrap items-end gap-2">
                @if ($showQty)
                    <div>
                        <label for="qty-{{ $productId }}" class="mb-1 block text-sm font-medium">{{ __('storefront_sales.add.qty') }}</label>
                        <input id="qty-{{ $productId }}" type="number" inputmode="numeric" min="1" @if (($limit = $actions->cartMaxQty($kind)) !== null) max="{{ $limit }}" @endif
                               wire:model="qty" wire:keydown.enter="add"
                               class="w-24 rounded-btn border border-line-strong px-3 py-3 focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary">
                    </div>
                @endif
                <button type="button" wire:click="add" wire:loading.attr="disabled" data-action="cart"
                        class="rounded-btn bg-primary px-6 py-3 font-medium text-on-primary hover:opacity-90 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:opacity-60">
                    {{ $cartLabel }}<span class="sr-only">: {{ $product->name }}</span>
                </button>
            </div>
        @else
            <button type="button" disabled data-action="unavailable" class="cursor-not-allowed rounded-btn bg-line px-6 py-3 font-medium text-muted">{{ __('sales.actions.out_of_stock') }}</button>
        @endif
    @endif

    <div aria-live="assertive" role="alert">
        @error('cart')
            <p class="text-sm text-danger">{{ $message }}</p>
        @enderror
    </div>
</div>
