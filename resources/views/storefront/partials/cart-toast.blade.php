{{-- Toast «Товар добавлен в корзину» (SF-41). The live region is always present so that screen readers announce the text when it appears. Fed by the `cart-toast` browser event (Livewire dispatch or the card form) and by session flash for the no-JS fallback. --}}
@php($flash = session('cart_flash') ?? session('cart_error'))
<div class="pointer-events-none shop-above-nav fixed inset-x-0 z-50 flex justify-center px-4 md:bottom-6 md:justify-end"
     x-data="cartToast(@js($flash ? ['message' => $flash, 'error' => session('cart_error') !== null] : null))" @cart-toast.window="show($event.detail)">
    <div role="status" aria-live="polite" class="pointer-events-auto">
        <div x-cloak x-show="visible" x-transition.opacity class="flex items-center gap-3 rounded-card px-4 py-3 text-sm shadow-lg" :class="error ? 'bg-danger text-on-solid' : 'bg-ink text-bg'">
            <span x-text="message">{{ $flash }}</span>
            <a x-show="! error" href="{{ route('cart') }}" class="font-semibold underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-bg">{{ __('storefront_sales.toast.to_cart') }}</a>
            <button type="button" @click="hide()" class="rounded-btn p-1 focus-visible:outline focus-visible:outline-2 focus-visible:outline-bg" aria-label="{{ __('storefront_sales.toast.close') }}">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 6l12 12M18 6L6 18"/></svg>
            </button>
        </div>
    </div>
</div>
