{{-- Shop logo / name (TH-10). The text uses the display font of the theme. --}}
<a href="{{ route('home') }}" class="{{ $class ?? '' }} flex min-h-11 items-center gap-2 rounded-btn font-display text-lg font-semibold text-primary focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary active:opacity-80 motion-reduce:transition-none">
    @if ($shopLogoUrl)
        <img src="{{ $shopLogoUrl }}" alt="" height="32" class="h-8 w-auto">
    @endif
    <span>{{ $shopName }}</span>
</a>
