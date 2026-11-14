{{-- TM-06: the compact top row of the Mini App: search and cart. The safe-area variables are overridden by tgBridge (TM-07). --}}
<header class="sticky top-0 z-30 border-b border-line bg-surface" data-tg-topbar
        style="padding-top: var(--shop-safe-top); padding-left: var(--shop-safe-left); padding-right: var(--shop-safe-right)">
    <div class="flex items-center gap-2 px-3 py-2">
        @if ($searchEnabled)
            @include('storefront.partials.header.search', ['class' => 'relative min-w-0 flex-1'])
        @else
            <a href="{{ route('home') }}" class="min-w-0 flex-1 truncate font-semibold">{{ $shopName }}</a>
        @endif
        @if ($cartEnabled)
            <livewire:storefront.mini-cart />
        @endif
    </div>
</header>
