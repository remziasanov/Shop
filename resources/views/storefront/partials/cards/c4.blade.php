{{-- C4 (TH-11): framed card with two characteristic lines (the first two filled `attributes` of the product, no extra queries) and the price with a compact button. Vars: see product-card.blade.php. --}}
@php
    $product = $view->product;
    $specs = array_slice(array_values(array_filter($product->specs(), fn (array $spec): bool => $spec['value'] !== '')), 0, 2);
    $inline = count($cardActions) === 1;
@endphp
<article class="group flex h-full flex-col rounded-card border border-line bg-surface p-3 transition hover:shadow-md focus-within:shadow-md" data-card="C4" @if ($view->age) data-age="{{ $view->age->value }}" @endif>
    @include('storefront.partials.cards._media', ['mediaClass' => 'aspect-[4/3] rounded-btn'])
    @include('storefront.partials.labels', ['labels' => $view->labels, 'class' => 'mt-2'])

    <h3 class="mt-2 font-body text-sm font-medium leading-snug">
        <a href="{{ $view->url }}" class="flex min-h-11 items-center hover:text-primary focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary">{{ $product->name }}</a>
    </h3>

    <ul class="mb-2 mt-1 min-h-9 flex-1 text-xs leading-snug text-ink-mid" data-card-specs>
        @foreach ($specs as $spec)
            <li class="truncate" title="{{ $spec['name'] }}: {{ $spec['value'] }}"><span class="text-muted">{{ $spec['name'] }}:</span> {{ $spec['value'] }}</li>
        @endforeach
    </ul>

    <div class="flex items-center justify-between gap-2">
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
