@if ($themePreviewBar ?? null)
    {{-- TH-27: staff theme preview bar. Every button is a POST (CSRF); the controller re-checks the staff right (TH-30). --}}
    <style>
        .theme-preview-bar{position:sticky;top:0;z-index:60;display:flex;flex-wrap:wrap;align-items:center;gap:.5rem .75rem;padding:.5rem 1rem;background:var(--shop-ink);color:var(--shop-bg);font:600 .8125rem/1.2 system-ui,sans-serif}
        .theme-preview-bar form{display:inline;margin:0}
        .theme-preview-bar button,.theme-preview-bar a{border:1px solid currentColor;border-radius:4px;background:transparent;color:inherit;padding:.25rem .625rem;font:inherit;cursor:pointer;text-decoration:none}
        .theme-preview-bar [data-apply]{background:var(--shop-bg);color:var(--shop-ink)}
        .theme-preview-bar [aria-current]{background:var(--shop-bg);color:var(--shop-ink)}
        .theme-preview-bar .spacer{flex:1}
    </style>
    <div class="theme-preview-bar" role="region" aria-label="{{ __('themes.preview.bar_label') }}" data-theme-preview-bar>
        <span>{{ __('themes.preview.title') }}: <strong data-theme-preview-name>{{ $themePreviewBar['name'] }}</strong> ({{ $themePreviewBar['index'] + 1 }}/{{ $themePreviewBar['total'] }})</span>
        <form method="POST" action="{{ route('theme.preview.step') }}">
            @csrf
            <input type="hidden" name="direction" value="prev">
            <button type="submit">{{ __('themes.preview.prev') }}</button>
        </form>
        <form method="POST" action="{{ route('theme.preview.step') }}">
            @csrf
            <input type="hidden" name="direction" value="next">
            <button type="submit">{{ __('themes.preview.next') }}</button>
        </form>
        <span role="group" aria-label="{{ __('themes.preview.device') }}">
            <a href="{{ route('home') }}" target="_top" @if (! request()->headers->has('Sec-Fetch-Dest') || request()->header('Sec-Fetch-Dest') !== 'iframe') aria-current="true" @endif>{{ __('themes.preview.desktop') }}</a>
            <a href="{{ \App\Filament\Pages\ThemePhonePreview::getUrl() }}" target="_top" @if (request()->header('Sec-Fetch-Dest') === 'iframe') aria-current="true" @endif>{{ __('themes.preview.phone') }}</a>
        </span>
        <span class="spacer"></span>
        <form method="POST" action="{{ route('theme.preview.apply') }}" target="_top">
            @csrf
            <button type="submit" data-apply>{{ __('themes.preview.apply') }}</button>
        </form>
        <form method="POST" action="{{ route('theme.preview.exit') }}" target="_top">
            @csrf
            <button type="submit">{{ __('themes.preview.exit') }}</button>
        </form>
    </div>
@endif
