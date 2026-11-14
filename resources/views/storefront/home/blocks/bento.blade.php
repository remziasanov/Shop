{{-- TH-12, G3: bento grid. The banner / slider block is the big tile, the first three category tiles of the following
     «Категории» block fill the rest. Categories beyond the third go on as the usual tile list below. $inner: banner|slider. --}}
@php
    $shown = array_slice($tiles, 0, 3);
    $rest = array_slice($tiles, 3);
    $count = count($shown);
    $bentoHeading = ($title ?? null) ? 'home-bento-'.\Illuminate\Support\Str::random(6) : null;
@endphp
<div class="mb-8" data-hero-bento>
    {{-- TH-12: the owner's block title is content, it stays visible above the bento grid. --}}
    @if ($bentoHeading)
        <h2 id="{{ $bentoHeading }}" class="mb-3 text-xl font-bold md:text-2xl" data-bento-title>{{ $title }}</h2>
    @endif
    <div class="grid gap-3 md:grid-cols-4 md:grid-rows-2 md:gap-4">
        <div class="hero-bento-main min-w-0 md:col-span-2 md:row-span-2">
            @include('storefront.home.blocks.'.$inner, ['bento' => true])
        </div>
        <ul class="grid grid-cols-2 gap-3 md:col-span-2 md:row-span-2 md:grid-rows-2 md:gap-4" @if ($bentoHeading) aria-labelledby="{{ $bentoHeading }}" @else aria-label="{{ __('storefront.catalog.categories') }}" @endif>
            @foreach ($shown as $tile)
                <li class="hero-bento-tile min-w-0 {{ $count === 1 ? 'col-span-2 md:row-span-2' : ($count === 2 ? 'md:row-span-2' : ($loop->iteration === 3 ? 'col-span-2' : '')) }}" data-tile data-tile-index="{{ $loop->index }}">
                    <a href="{{ $tile['url'] }}" class="flex h-full min-h-32 flex-col justify-between gap-3 rounded-card border p-4 hover:shadow-sm active:opacity-90 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary motion-reduce:transition-none">
                        <span class="min-w-0">
                            <span class="block break-words text-lg font-bold leading-snug">{{ $tile['name'] }}</span>
                            <span class="block text-xs opacity-70">{{ trans_choice('storefront.catalog.products_count', $tile['count']) }}</span>
                        </span>
                        <span class="h-14 w-14 flex-none self-end overflow-hidden rounded-btn bg-surface-2">
                            @if ($tile['image'])
                                <img src="{{ $tile['image'] }}" alt="" width="56" height="56" loading="lazy" class="h-full w-full object-cover">
                            @else
                                @include('storefront.partials.image-placeholder', ['url' => null, 'alt' => ''])
                            @endif
                        </span>
                    </a>
                </li>
            @endforeach
        </ul>
    </div>
</div>
@if ($rest !== [])
    @include('storefront.partials.category-tiles', ['tiles' => $rest, 'title' => $title ?? null, 'headingId' => 'home-cat-'.\Illuminate\Support\Str::random(6)])
@endif
