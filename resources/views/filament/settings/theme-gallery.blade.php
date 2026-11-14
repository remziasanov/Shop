<div class="shop-tg" data-theme-gallery>
    <style>
        .shop-tg { display: flex; flex-direction: column; gap: 1.5rem; }
        .shop-tg-group-title { margin: 0 0 .5rem; font-size: .875rem; font-weight: 600; color: var(--gray-950); }
        .dark .shop-tg-group-title { color: #fff; }
        .shop-tg-grid { display: grid; gap: .75rem; grid-template-columns: repeat(auto-fill, minmax(min(240px, 100%), 1fr)); }
        .shop-tg-card { display: flex; flex-direction: column; gap: .5rem; padding: .875rem; border-radius: .75rem; font-size: .875rem;
            border: 1px solid var(--gray-200); background: #fff; box-shadow: 0 1px 2px rgba(0, 0, 0, .05); }
        .dark .shop-tg-card { border-color: rgba(255, 255, 255, .1); background: rgba(255, 255, 255, .05); }
        .shop-tg-card--active { border-color: var(--primary-600); box-shadow: 0 0 0 2px var(--primary-600); }
        .shop-tg-head { display: flex; align-items: flex-start; justify-content: space-between; gap: .5rem; }
        .shop-tg-name { font-weight: 600; color: var(--gray-950); }
        .dark .shop-tg-name { color: #fff; }
        .shop-tg-meta { margin: 0; font-size: .75rem; color: var(--gray-500); }
        .dark .shop-tg-meta { color: var(--gray-400); }
        .shop-tg-swatches { display: flex; gap: .375rem; }
        .shop-tg-swatch { display: block; width: 1.75rem; height: 1.75rem; border-radius: .375rem; border: 1px solid rgba(128, 128, 128, .45); }
        .shop-tg-actions { display: flex; flex-wrap: wrap; gap: .5rem; margin-top: auto; padding-top: .25rem; }
    </style>
    @foreach ($groups as $group)
        <div>
            <h3 class="shop-tg-group-title">{{ $group['title'] }}</h3>
            <div class="shop-tg-grid">
                @foreach ($group['themes'] as $card)
                    <div @class(['shop-tg-card', 'shop-tg-card--active' => $card['active']])
                        data-theme-card="{{ $card['id'] }}" @if ($card['active']) data-theme-active @endif>
                        <div class="shop-tg-head">
                            <div class="shop-tg-name">{{ $card['name'] }}</div>
                            @if ($card['active'])
                                <x-filament::badge color="primary" size="sm">{{ __('themes.admin.active') }}</x-filament::badge>
                            @endif
                        </div>
                        @if ($card['niche'])
                            <p class="shop-tg-meta">{{ __('themes.admin.niche', ['niche' => $card['niche']]) }}</p>
                        @endif
                        <div class="shop-tg-swatches" aria-hidden="true">
                            @foreach ($card['swatches'] as $swatch)
                                <span class="shop-tg-swatch" style="background-color: {{ e($swatch) }}"></span>
                            @endforeach
                        </div>
                        <p class="shop-tg-meta">{{ __('themes.admin.fonts_line', ['display' => $card['display'], 'body' => $card['body']]) }}</p>
                        @if ($canEdit)
                            <div class="shop-tg-actions">
                                <x-filament::button size="sm" color="gray" type="button" wire:click="previewTheme('{{ $card['id'] }}')">{{ __('themes.admin.preview') }}</x-filament::button>
                                <x-filament::button size="sm" type="button" wire:click="selectTheme('{{ $card['id'] }}')" :disabled="$card['active']">{{ __('themes.admin.select') }}</x-filament::button>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    @endforeach
</div>
