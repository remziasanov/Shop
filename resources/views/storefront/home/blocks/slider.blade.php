{{-- Home page slider block (5.2): scroll-snap track (swipe works natively, no-JS fallback), Alpine adds autoplay and controls. --}}
@php
    $count = count($slides);
    $hero = \App\Services\Themes\BlockExtras::hero($theme ?? null);
    $bento = $bento ?? false;
    $band = in_array($hero, ['G2', 'G4'], true);
    $heroStyle = \App\Services\Themes\BlockExtras::heroStyle($theme ?? null);
@endphp
<section @class([$bento ? 'relative h-full' : 'mb-8', 'hero-band' => $band, 'hero-band-first' => $band && $first]) aria-roledescription="carousel" aria-label="{{ __('home.storefront.slider') }}"
         data-hero="{{ $hero }}" @if ($heroStyle !== '') style="{{ $heroStyle }}" @endif
         @if ($count > 1)
             x-data="homeSlider({ autoplay: {{ $autoplay ? 'true' : 'false' }}, interval: {{ $interval }} })"
             @mouseenter="hover = true" @mouseleave="hover = false" @focusin="focus = true" @focusout="focus = false"
             @keydown.arrow-left="prev()" @keydown.arrow-right="next()"
         @endif>
    @if ($band)<div class="hero-band-inner">@endif
    <div class="relative {{ $bento ? 'md:h-full' : '' }}">
        <div @if ($count > 1) x-ref="track" @scroll.passive="onScroll()" :aria-live="playing ? 'off' : 'polite'" @endif
             class="flex snap-x snap-mandatory overflow-x-auto [scrollbar-width:none] [&::-webkit-scrollbar]:hidden {{ $bento ? 'md:h-full' : '' }}">
            @foreach ($slides as $slide)
                <div class="w-full flex-none snap-center {{ $bento ? 'md:h-full' : '' }}" role="group" aria-roledescription="slide" aria-label="{{ __('home.storefront.slide_of', ['n' => $loop->iteration, 'total' => $count]) }}">
                    @include('storefront.home.blocks._slide', ['slide' => $slide, 'eager' => $first && $loop->first])
                </div>
            @endforeach
        </div>

        @if ($count > 1 && ! $band && ! $bento)
            <div x-cloak>
                <button type="button" @click="prev()" class="absolute left-2 top-1/2 hidden h-10 w-10 -translate-y-1/2 items-center justify-center rounded-full bg-surface/80 text-ink shadow hover:bg-surface focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary md:flex" aria-label="{{ __('home.storefront.prev') }}">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
                </button>
                <button type="button" @click="next()" class="absolute right-2 top-1/2 hidden h-10 w-10 -translate-y-1/2 items-center justify-center rounded-full bg-surface/80 text-ink shadow hover:bg-surface focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary md:flex" aria-label="{{ __('home.storefront.next') }}">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                </button>
            </div>
        @endif
    </div>

    @if ($count > 1)
        <div class="{{ $bento ? 'absolute right-3 top-3 z-10 flex items-center gap-1 rounded-full bg-surface/80 px-1 text-ink' : 'mt-3 flex items-center justify-center gap-3' }}" x-cloak>
            @if ($autoplay)
                <button type="button" @click="toggle()" class="flex h-11 w-11 items-center justify-center rounded-full border border-line-strong text-ink-soft hover:bg-surface-2 focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary"
                        :aria-label="playing ? @js(__('home.storefront.pause')) : @js(__('home.storefront.play'))">
                    <svg x-show="playing" class="h-4 w-4" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path d="M7 5h4v14H7zM13 5h4v14h-4z"/></svg>
                    <svg x-show="! playing" class="h-4 w-4" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path d="M8 5l11 7-11 7z"/></svg>
                </button>
            @endif
            <div class="flex">
                @foreach ($slides as $slide)
                    <button type="button" @click="go({{ $loop->index }})" :aria-current="index === {{ $loop->index }} ? 'true' : null"
                            class="group flex h-11 w-7 items-center justify-center focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
                            aria-label="{{ __('home.storefront.go_to', ['n' => $loop->iteration]) }}">
                        <span class="h-2.5 w-2.5 rounded-full bg-line-strong" :class="index === {{ $loop->index }} ? '!bg-primary' : ''"></span>
                    </button>
                @endforeach
            </div>
        </div>
    @endif
    @if ($band)</div>@endif
</section>
