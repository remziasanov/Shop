{{-- C3 (TH-11): soft tile with a round «+» button (cart); other actions become pill buttons under the price. Vars: see product-card.blade.php. --}}
@php($product = $view->product)
@php($roundAction = count($cardActions) === 1 && ! $cardActions[0]['disabled'] && $cardActions[0]['key'] === 'cart')
<article class="group flex h-full flex-col rounded-card bg-surface-2 p-3 transition hover:shadow-md focus-within:shadow-md" data-card="C3" @if ($view->age) data-age="{{ $view->age->value }}" @endif>
    @include('storefront.partials.cards._media', ['mediaClass' => 'aspect-square rounded-btn'])
    @include('storefront.partials.labels', ['labels' => $view->labels, 'class' => 'mt-2'])

    <h3 class="mt-2 flex-1 px-1 font-body text-sm font-medium leading-snug">
        <a href="{{ $view->url }}" class="flex min-h-11 items-center hover:text-primary focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary">{{ $product->name }}</a>
    </h3>

    <div class="mt-2 flex items-center justify-between gap-2 pl-1">
        <div class="min-w-0">
            @include('storefront.partials.price', ['view' => $view])
            @if ($view->tierCaption)
                <p class="text-xs text-ink-mid" data-tier-caption>{{ $view->tierCaption }}</p>
            @endif
        </div>
        @if ($roundAction)
            @include('storefront.partials.cards._actions', ['style' => 'round'])
        @endif
    </div>
    <div class="mt-1 min-h-5 pl-1">@include('storefront.partials.availability', ['availability' => $view->availability])</div>

    @if ($cardActions !== [] && ! $roundAction)
        <div class="mt-3 flex flex-col gap-2">
            @include('storefront.partials.cards._actions', ['style' => 'round'])
        </div>
    @endif
</article>
