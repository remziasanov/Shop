{{-- C5 (TH-11): price-list row: photo, name, SKU, price by quantity tiers (the viewer's group, GP-10), price, button. The row switches to a compact stacked layout by the width of the card itself (container query), so it also works in a narrow grid cell or on a phone. Vars: see product-card.blade.php. --}}
@php
    $product = $view->product;
    $tierRows = array_slice($view->tiers, 0, 3);
@endphp
<article class="border-b border-line" data-card="C5" @if ($view->age) data-age="{{ $view->age->value }}" @endif>
    <div class="@container">
        <div class="group grid grid-cols-[5rem_minmax(0,1fr)] gap-x-3 gap-y-2 py-3 @2xl:flex @2xl:items-center @2xl:gap-4 @2xl:py-2">
            <div class="@2xl:order-1 @2xl:w-16 @2xl:flex-none">
                @include('storefront.partials.cards._media', ['mediaClass' => 'aspect-square rounded-btn', 'sizes' => '96px'])
            </div>

            <div class="min-w-0 @2xl:contents">
                <div class="min-w-0 @2xl:order-2 @2xl:flex-1">
                    <h3 class="font-body text-base font-medium leading-snug">
                        <a href="{{ $view->url }}" class="flex min-h-11 items-center hover:text-primary focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary">{{ $product->name }}</a>
                    </h3>
                    @include('storefront.partials.labels', ['labels' => $view->labels, 'class' => 'mb-1'])
                </div>
                @if ($product->sku)
                    <p class="text-xs text-muted @2xl:order-4 @2xl:w-36 @2xl:flex-none" data-card-sku>{{ __('storefront.product.sku') }}: {{ $product->sku }}</p>
                @endif
                <div class="min-h-5 @2xl:order-3 @2xl:w-32 @2xl:flex-none">@include('storefront.partials.availability', ['availability' => $view->availability])</div>
            </div>

            <div class="col-span-2 flex flex-wrap items-center gap-x-4 gap-y-1 @2xl:contents">
                @include('storefront.partials.price', ['view' => $view, 'class' => '@2xl:order-6 @2xl:w-28 @2xl:flex-none @2xl:text-right'])
                @if ($tierRows !== [])
                    <ul class="text-xs font-medium text-ink-mid @2xl:order-5 @2xl:w-44 @2xl:flex-none" data-tier-rows>
                        @foreach ($tierRows as $tier)
                            <li>{{ __('group_prices.tier_caption', ['qty' => $tier['min'], 'price' => $tier['price']]) }}</li>
                        @endforeach
                    </ul>
                @endif
                @if ($cardActions !== [])
                    <div class="ml-auto flex flex-col gap-2 @2xl:order-7 @2xl:w-44 @2xl:flex-none">
                        @include('storefront.partials.cards._actions', ['style' => 'c1', 'soft' => true])
                    </div>
                @endif
            </div>
        </div>
    </div>
</article>
