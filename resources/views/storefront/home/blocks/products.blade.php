{{-- Home page product grid block (5.2): product cards are the catalog's (SF-15); columns follow the appearance settings. --}}
<section class="mb-8" @if ($title ?? null) aria-label="{{ $title }}" @endif>
    @if (($title ?? null) || ($allUrl ?? null))
        <div class="mb-3 flex items-baseline justify-between gap-4">
            @if ($title ?? null)
                <h2 class="text-xl font-bold md:text-2xl">{{ $title }}</h2>
            @endif
            @if ($allUrl ?? null)
                <a href="{{ $allUrl }}" class="ml-auto text-sm font-medium text-primary hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary">{{ __('home.storefront.view_all') }}<span class="sr-only">{{ ($title ?? null) ? ': '.$title : '' }}</span></a>
            @endif
        </div>
    @endif
    <ul class="grid grid-cols-2 gap-3 md:grid-cols-3 md:gap-4 {{ $columns === 3 ? 'lg:grid-cols-3' : 'lg:grid-cols-4' }}">
        @foreach ($cards as $card)
            <li>@include('storefront.partials.product-card', ['view' => $card, 'eager' => $first && $loop->index < 4])</li>
        @endforeach
    </ul>
</section>
