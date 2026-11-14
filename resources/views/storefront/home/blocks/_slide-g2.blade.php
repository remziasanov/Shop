{{-- G2: text and image half and half (on the hero band: heroBg / heroInk). --}}
<div class="grid items-center gap-6 md:grid-cols-2 md:gap-10" data-hero-slide>
    <div class="order-2 min-w-0 md:order-1">
        @if ($slide['title'])
            <h2 class="hero-title-md font-bold">
                @if ($slide['url'] && ! $slide['buttonText'])
                    <a href="{{ $slide['url'] }}" class="focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-primary">{{ $slide['title'] }}</a>
                @else
                    {{ $slide['title'] }}
                @endif
            </h2>
        @endif
        @if ($slide['subtitle'])
            <p class="mt-3 max-w-xl text-base opacity-80 md:mt-4 md:text-lg">{{ $slide['subtitle'] }}</p>
        @endif
        @if ($slide['buttonText'])
            <a href="{{ $slide['url'] }}" class="hero-btn mt-5 inline-flex min-h-11 items-center rounded-btn bg-primary px-6 py-3 text-sm font-semibold text-on-primary hover:opacity-90 active:opacity-80 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary motion-reduce:transition-none md:mt-7">{{ $slide['buttonText'] }}</a>
        @endif
    </div>
    <div class="order-1 overflow-hidden rounded-card bg-surface-3 md:order-2">
        @include('storefront.home.blocks._picture', ['class' => 'block aspect-[4/3] h-full w-full object-cover', 'sizes' => '(min-width: 1280px) 600px, (min-width: 768px) 50vw, 100vw'])
    </div>
</div>
