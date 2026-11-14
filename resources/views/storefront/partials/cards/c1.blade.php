{{-- C1 (TH-11): frame, full-width button. This is the card of the `default` theme (pixel-identical to the card before themes). Vars from product-card.blade.php: $view, $eager, $cardActions, $tileIndex. --}}
@php($product = $view->product)
<article class="group flex h-full flex-col rounded-card border border-line bg-surface p-3 transition hover:shadow-md focus-within:shadow-md" data-card="C1" @if ($view->age) data-age="{{ $view->age->value }}" @endif>
    @include('storefront.partials.cards._media', ['mediaClass' => 'aspect-square rounded-btn'])
    @include('storefront.partials.labels', ['labels' => $view->labels, 'class' => 'mt-2'])

    <h3 class="mt-2 flex-1 font-body text-sm font-medium leading-snug">
        <a href="{{ $view->url }}" class="flex min-h-11 items-center hover:text-primary focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary">{{ $product->name }}</a>
    </h3>

    @include('storefront.partials.price', ['view' => $view, 'class' => 'mt-2'])
    @if ($view->tierCaption)
        {{-- GP-09: the first quantity tier of the viewer's group. --}}
        <p class="text-xs text-ink-mid" data-tier-caption>{{ $view->tierCaption }}</p>
    @endif
    <div class="mt-1 min-h-5">@include('storefront.partials.availability', ['availability' => $view->availability])</div>

    @if ($view->actions !== [])
        <div class="mt-3 flex flex-col gap-2">
            @include('storefront.partials.cards._actions', ['style' => 'c1'])
        </div>
    @endif
</article>
