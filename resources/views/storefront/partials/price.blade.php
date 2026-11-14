{{-- Price block: final price, old price struck through, discount percent (SF-15, SF-31, SF-36). $view: ProductView. --}}
<div class="{{ $class ?? '' }}">
    @if ($view->priceLoginUrl)
        {{-- CG-06: the group sees no prices, a sign-in link instead. --}}
        <p class="font-semibold"><a href="{{ $view->priceLoginUrl }}" class="text-primary underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary">{{ $view->price }}</a></p>
    @elseif ($view->priceOnRequest)
        <p class="font-semibold text-ink-soft">{{ $view->price }}</p>
    @else
        <p class="flex flex-wrap items-baseline gap-x-2">
            <span class="{{ $size ?? 'text-lg' }} font-bold">{{ $view->price }}</span>
            @if ($view->oldPrice)
                <s class="text-sm text-muted"><span class="sr-only">{{ __('storefront.labels.sale') }}:</span> {{ $view->oldPrice }}</s>
                <span class="rounded-btn bg-danger-wash-2 px-1.5 py-0.5 text-xs font-semibold text-danger">{{ __('storefront.product.discount', ['percent' => $view->discount]) }}</span>
            @endif
            @if ($view->retailPrice)
                {{-- GP-09: the retail price of a group with «показывать розничную цену». --}}
                <s class="text-sm text-muted" data-retail-price><span class="sr-only">{{ __('group_prices.retail_price') }}:</span> {{ $view->retailPrice }}</s>
            @endif
        </p>
    @endif
</div>
