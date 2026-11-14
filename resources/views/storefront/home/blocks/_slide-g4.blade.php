{{-- G4: large full-width typography; the image is a small panel on the side. --}}
<div class="grid gap-6 md:grid-cols-[minmax(0,1fr)_minmax(0,22rem)] md:gap-10" data-hero-slide>
    <div class="flex min-w-0 flex-col justify-between gap-6 md:gap-10">
        @if ($slide['title'])
            <h2 class="hero-title-xl break-words font-bold">
                @if ($slide['url'] && ! $slide['buttonText'])
                    <a href="{{ $slide['url'] }}" class="focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-primary">{{ $slide['title'] }}</a>
                @else
                    {{ $slide['title'] }}
                @endif
            </h2>
        @endif
        @if ($slide['subtitle'] || $slide['buttonText'])
            <div class="flex flex-wrap items-end justify-between gap-x-6 gap-y-3">
                @if ($slide['subtitle'])
                    <p class="max-w-md text-base opacity-80">{{ $slide['subtitle'] }}</p>
                @endif
                @if ($slide['buttonText'])
                    <a href="{{ $slide['url'] }}" class="inline-flex min-h-11 items-center font-semibold underline decoration-2 underline-offset-4 hover:opacity-80 active:opacity-70 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-primary motion-reduce:transition-none">{{ $slide['buttonText'] }}</a>
                @endif
            </div>
        @endif
    </div>
    <div class="self-start overflow-hidden rounded-card bg-surface-3">
        @include('storefront.home.blocks._picture', ['class' => 'block aspect-[3/2] w-full object-cover', 'sizes' => '(min-width: 768px) 352px, 100vw'])
    </div>
</div>
