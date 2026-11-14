{{-- C6 (TH-11): grid cell with numbering; the grid has no gaps (cells share one hairline, see card-grid component and cards-listing.css). Vars: see product-card.blade.php; $number = position in the listing. --}}
@php
    $product = $view->product;
    $inline = count($cardActions) === 1;
@endphp
<article class="group flex h-full flex-col border border-line-strong bg-surface p-3" data-card="C6" @if ($view->age) data-age="{{ $view->age->value }}" @endif>
    <div class="flex min-h-6 items-center justify-between gap-2 text-xs text-muted">
        <span class="font-mono" data-card-number aria-hidden="true">{{ sprintf('%02d', $number ?? ($tileIndex + 1)) }}</span>
        @include('storefront.partials.labels', ['labels' => $view->labels])
    </div>
    <div class="mt-2">
        @include('storefront.partials.cards._media', ['mediaClass' => 'aspect-[4/3]'])
    </div>

    <h3 class="mt-2 flex-1 font-body text-sm font-medium leading-snug">
        <a href="{{ $view->url }}" class="flex min-h-11 items-center hover:text-primary focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary">{{ $product->name }}</a>
    </h3>

    <div class="mt-1 flex items-center justify-between gap-2">
        <div class="min-w-0">
            @include('storefront.partials.price', ['view' => $view])
            @if ($view->tierCaption)
                <p class="text-xs text-ink-mid" data-tier-caption>{{ $view->tierCaption }}</p>
            @endif
        </div>
        @if ($inline)
            @include('storefront.partials.cards._actions', ['style' => 'compact'])
        @endif
    </div>
    <div class="mt-1 min-h-5">@include('storefront.partials.availability', ['availability' => $view->availability])</div>

    @if ($cardActions !== [] && ! $inline)
        <div class="mt-3 flex flex-col gap-2">
            @include('storefront.partials.cards._actions', ['style' => 'c1', 'soft' => true])
        </div>
    @endif
</article>
