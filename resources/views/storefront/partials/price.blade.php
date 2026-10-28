{{-- Price block: final price, old price struck through, discount percent (SF-15, SF-31, SF-36). $view: ProductView. --}}
<div class="{{ $class ?? '' }}">
    @if ($view->priceLoginUrl)
        {{-- CG-06: the group sees no prices, a sign-in link instead. --}}
        <p class="font-semibold"><a href="{{ $view->priceLoginUrl }}" class="text-primary underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary">{{ $view->price }}</a></p>
    @elseif ($view->priceOnRequest)
        <p class="font-semibold text-slate-700">{{ $view->price }}</p>
    @else
        <p class="flex flex-wrap items-baseline gap-x-2">
            <span class="{{ $size ?? 'text-lg' }} font-bold">{{ $view->price }}</span>
            @if ($view->oldPrice)
                <s class="text-sm text-slate-500"><span class="sr-only">{{ __('storefront.labels.sale') }}:</span> {{ $view->oldPrice }}</s>
                <span class="rounded bg-red-100 px-1.5 py-0.5 text-xs font-semibold text-red-700">{{ __('storefront.product.discount', ['percent' => $view->discount]) }}</span>
            @endif
        </p>
    @endif
</div>
