{{-- TH-10, H2: one row. Logo, menu, icons (the search icon opens a search row). Below 768 px: menu, logo, cart. --}}
<div class="mx-auto flex w-full max-w-7xl items-center gap-3 px-4 py-3">
    @include('storefront.partials.header.burger')
    @include('storefront.partials.header.logo')
    @if ($menuCategories !== [])
        @include('storefront.partials.header.nav', ['class' => 'ml-2 hidden min-w-0 md:block', 'listClass' => 'flex flex-wrap gap-x-1 text-sm'])
    @endif
    <div class="ml-auto flex items-center gap-1 md:gap-2">
        @include('storefront.partials.header.icons', ['compact' => true, 'searchToggle' => true])
    </div>
</div>
@if ($searchEnabled)
    <div class="mx-auto w-full max-w-7xl px-4">
        @include('storefront.partials.header.search', ['panel' => true, 'class' => 'relative mb-3'])
    </div>
@endif
