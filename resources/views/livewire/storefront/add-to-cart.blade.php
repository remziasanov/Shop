<div class="flex flex-col gap-2" data-add-to-cart>
    @if ($product !== null && $actions !== null)
        @if ($actions->canAddToCart)
            <div class="flex flex-wrap items-end gap-2">
                @if ($showQty)
                    <div>
                        <label for="qty-{{ $productId }}" class="mb-1 block text-sm font-medium">{{ __('storefront_sales.add.qty') }}</label>
                        <input id="qty-{{ $productId }}" type="number" inputmode="numeric" min="1" @if ($actions->maxQty !== null) max="{{ $actions->maxQty }}" @endif
                               wire:model="qty" wire:keydown.enter="add"
                               class="w-24 rounded border border-slate-300 px-3 py-3 focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary">
                    </div>
                @endif
                <button type="button" wire:click="add" wire:loading.attr="disabled" data-action="cart"
                        class="rounded bg-primary px-6 py-3 font-medium text-white hover:opacity-90 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:opacity-60">
                    {{ $actions->backorder ? __('sales.actions.backorder') : __('storefront.actions.cart') }}<span class="sr-only">: {{ $product->name }}</span>
                </button>
            </div>
        @else
            <button type="button" disabled data-action="unavailable" class="cursor-not-allowed rounded bg-slate-200 px-6 py-3 font-medium text-slate-500">{{ __('sales.actions.out_of_stock') }}</button>
        @endif
    @endif

    <div aria-live="assertive" role="alert">
        @error('cart')
            <p class="text-sm text-red-700">{{ $message }}</p>
        @enderror
    </div>
</div>
