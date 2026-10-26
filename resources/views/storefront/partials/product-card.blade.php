{{-- SF-15. $eager: first cards of a listing are above the fold and load eagerly (NF-03). $view: App\Services\Catalog\ProductView. Cart button = plain POST form (no Livewire component per card, NF-02), enhanced by Alpine `cardAdd`; reservation / inquiry open the product page with the modal. --}}
@php($product = $view->product)
<article class="group flex h-full flex-col rounded-lg border border-slate-200 bg-white p-3 transition hover:shadow-md focus-within:shadow-md">
    <a href="{{ $view->url }}" class="relative block aspect-square overflow-hidden rounded bg-slate-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary" tabindex="-1">
        @if ($view->image)
            <img src="{{ $view->image['src'] }}" srcset="{{ $view->image['srcset'] }}" sizes="(min-width: 1280px) 20vw, (min-width: 768px) 30vw, 50vw"
                 width="{{ $view->image['width'] }}" height="{{ $view->image['height'] }}" alt="{{ $view->image['alt'] }}" @if (! ($eager ?? false)) loading="lazy" @endif decoding="async"
                 class="h-full w-full object-contain">
        @else
            @include('storefront.partials.image-placeholder', ['url' => $view->placeholderUrl, 'alt' => $product->name])
        @endif
    </a>
    @include('storefront.partials.labels', ['labels' => $view->labels, 'class' => 'mt-2'])

    <h3 class="mt-2 flex-1 text-sm font-medium leading-snug">
        <a href="{{ $view->url }}" class="hover:text-primary focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary">{{ $product->name }}</a>
    </h3>

    @include('storefront.partials.price', ['view' => $view, 'class' => 'mt-2'])
    <div class="mt-1 min-h-5">@include('storefront.partials.availability', ['availability' => $view->availability])</div>

    @if ($view->actions !== [])
        <div class="mt-3 flex flex-col gap-2">
            @foreach ($view->actions as $action)
                @if ($action['disabled'])
                    <span aria-disabled="true" class="cursor-not-allowed rounded bg-slate-200 px-3 py-2 text-center text-sm font-medium text-slate-500">{{ $action['label'] }}</span>
                @elseif ($action['key'] === 'cart')
                    <form method="post" action="{{ route('cart.add', [], false) }}" x-data="cardAdd" @submit.prevent="submit($event)" data-error="{{ __('sales.guard.generic') }}">
                        @csrf
                        <input type="hidden" name="product_id" value="{{ $product->id }}">
                        <button type="submit" data-action="cart" :disabled="busy" class="w-full rounded bg-primary px-3 py-2 text-center text-sm font-medium text-white hover:opacity-90 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:opacity-60">{{ $action['label'] }}<span class="sr-only">: {{ $product->name }}</span></button>
                    </form>
                @else
                    <a href="{{ $view->url }}?{{ $action['key'] === 'inquiry' ? 'inquiry' : 'reserve' }}=1" class="rounded px-3 py-2 text-center text-sm font-medium focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary {{ $action['primary'] ? 'bg-primary text-white hover:opacity-90' : 'border border-primary text-primary hover:bg-slate-50' }}">{{ $action['label'] }}<span class="sr-only">: {{ $product->name }}</span></a>
                @endif
            @endforeach
        </div>
    @endif
</article>
