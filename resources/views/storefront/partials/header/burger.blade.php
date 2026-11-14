<button type="button" class="rounded-btn p-2 hover:bg-surface-3 active:opacity-80 focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary motion-reduce:transition-none md:hidden"
        aria-label="{{ __('storefront.header.menu') }}" aria-controls="mobile-menu" :aria-expanded="menu.toString()" aria-expanded="false" @click="menu = ! menu">
    <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" d="M4 6h16M4 12h16M4 18h16"/></svg>
</button>
