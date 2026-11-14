{{-- Photo tile of a card (shared by C1…C6). $view: ProductView; $eager; $mediaClass: shape/size classes of the tile; $sizes: img sizes override; $tileIndex: position (hook `data-tile-index`). Age blur and badge: the `age-media` classes, decided in CSS (AG). --}}
@php($product = $view->product)
<a href="{{ $view->url }}" class="age-media relative block overflow-hidden {{ $mediaClass }} bg-tile focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary" tabindex="-1" data-tile data-tile-index="{{ $tileIndex ?? 0 }}">
    @if ($view->image)
        <img src="{{ $view->image['src'] }}" srcset="{{ $view->image['srcset'] }}" sizes="{{ $sizes ?? '(min-width: 1280px) 20vw, (min-width: 768px) 30vw, '.(($mobileColumns ?? 2) === 1 ? 'calc(100vw - 2rem)' : 'min(400px, calc(50vw - 1.5rem))') }}"
             width="{{ $view->image['width'] }}" height="{{ $view->image['height'] }}" alt="{{ $view->image['alt'] }}" @if (! ($eager ?? false)) loading="lazy" @endif decoding="async"
             class="age-media-img h-full w-full object-contain">
    @else
        @include('storefront.partials.image-placeholder', ['url' => $view->placeholderUrl, 'alt' => $product->name])
    @endif
    @if ($view->age)
        <span class="age-badge">{{ $view->age->badge() }}</span>
    @endif
</a>
