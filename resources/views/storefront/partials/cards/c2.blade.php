{{-- C2 (TH-11): large photo without a frame, price and a «В корзину» text link. Vars: see product-card.blade.php. --}}
@php($product = $view->product)
<article class="group flex h-full flex-col" data-card="C2" @if ($view->age) data-age="{{ $view->age->value }}" @endif>
    @include('storefront.partials.cards._media', ['mediaClass' => 'aspect-[9/8] rounded-btn'])
    @include('storefront.partials.labels', ['labels' => $view->labels, 'class' => 'mt-2'])

    <h3 class="mt-3 flex-1 font-body text-sm font-medium leading-snug">
        <a href="{{ $view->url }}" class="flex min-h-11 items-center hover:text-primary focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary">{{ $product->name }}</a>
    </h3>

    <div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1">
        @include('storefront.partials.price', ['view' => $view, 'class' => 'min-w-0'])
        @if ($cardActions !== [])
            <div class="ml-auto flex flex-col items-end">
                @include('storefront.partials.cards._actions', ['style' => 'link'])
            </div>
        @endif
    </div>
    @if ($view->tierCaption)
        <p class="text-xs text-ink-mid" data-tier-caption>{{ $view->tierCaption }}</p>
    @endif
    <div class="mt-1 min-h-5">@include('storefront.partials.availability', ['availability' => $view->availability])</div>
</article>
