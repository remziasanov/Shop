{{-- Shop logo / name (TH-10). The text uses the display font of the theme; in the 50 themes it is the text color, as in every preview (the default theme keeps its primary-colored name). --}}
@php($logoColor = ($theme->id ?? \App\Services\Themes\ThemeRegistry::DEFAULT_ID) === \App\Services\Themes\ThemeRegistry::DEFAULT_ID ? 'text-primary' : 'text-ink')
<a href="{{ route('home') }}" class="{{ $class ?? '' }} flex min-h-11 items-center gap-2 rounded-btn font-display text-lg font-semibold {{ $logoColor }} focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary active:opacity-80 motion-reduce:transition-none">
    @if ($shopLogoUrl)
        <img src="{{ $shopLogoUrl }}" alt="" height="32" class="h-8 w-auto">
    @endif
    <span>{{ $shopName }}</span>
</a>
