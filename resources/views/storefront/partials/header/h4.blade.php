{{-- TH-10, H4: menu on the left, logo in the centre, icons on the right. Below 768 px: menu, logo, cart. --}}
<div class="mx-auto grid w-full max-w-7xl grid-cols-[1fr_auto_1fr] items-center gap-3 px-4 py-3">
    <div class="flex min-w-0 items-center">
        @include('storefront.partials.header.burger')
        @if ($menuCategories !== [])
            @include('storefront.partials.header.nav', ['class' => 'hidden min-w-0 md:block -ml-3', 'listClass' => 'flex flex-wrap gap-x-1 text-sm'])
        @endif
    </div>
    @include('storefront.partials.header.logo', ['class' => 'justify-center'])
    <div class="flex items-center justify-end gap-1 md:gap-2">
        @include('storefront.partials.header.icons', ['compact' => true, 'searchToggle' => true])
    </div>
</div>
@if ($searchEnabled)
    <div class="mx-auto w-full max-w-7xl px-4">
        @include('storefront.partials.header.search', ['panel' => true, 'class' => 'relative mb-3'])
    </div>
@endif
