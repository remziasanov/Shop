@php
    // D-210: @font-face rules of the fonts the miniatures show (one rule per face). A face is downloaded only when text
    // that uses it is laid out; cards outside the viewport are skipped by `content-visibility: auto`.
    $faces = collect($groups)->flatMap(fn (array $group) => collect($group['themes'])->flatMap(fn (array $card) => $card['mini_faces']))->unique()->implode('');
@endphp
<div class="shop-tg" data-theme-gallery>
    <style>
        {!! str_replace(['<', '>'], '', $faces) !!}
        .shop-tg { display: flex; flex-direction: column; gap: 1.5rem; }
        .shop-tg-group-title { margin: 0 0 .5rem; font-size: .875rem; font-weight: 600; color: var(--gray-950); }
        .dark .shop-tg-group-title { color: #fff; }
        .shop-tg-grid { display: grid; gap: 1rem; grid-template-columns: repeat(3, minmax(0, 1fr)); }
        @media (max-width: 1023px) { .shop-tg-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        @media (max-width: 639px) { .shop-tg-grid { grid-template-columns: minmax(0, 1fr); } }
        .shop-tg-card { display: flex; flex-direction: column; overflow: hidden; border-radius: .75rem; font-size: .875rem;
            border: 1px solid var(--gray-200); background: #fff; box-shadow: 0 1px 2px rgba(0, 0, 0, .05); }
        .dark .shop-tg-card { border-color: rgba(255, 255, 255, .1); background: rgba(255, 255, 255, .05); }
        .shop-tg-card--active { border-color: var(--primary-600); box-shadow: 0 0 0 2px var(--primary-600); }
        .shop-tg-body { display: flex; flex: 1; flex-direction: column; gap: .5rem; padding: .875rem; }
        .shop-tg-head { display: flex; align-items: flex-start; justify-content: space-between; gap: .5rem; }
        .shop-tg-name { font-weight: 600; color: var(--gray-950); }
        .dark .shop-tg-name { color: #fff; }
        .shop-tg-meta { margin: 0; font-size: .75rem; color: var(--gray-500); }
        .dark .shop-tg-meta { color: var(--gray-400); }
        .shop-tg-actions { display: flex; flex-wrap: wrap; gap: .5rem; margin-top: auto; padding-top: .25rem; }

        /* Schematic storefront miniature: drawn only with the --c-* tokens of the card. */
        .shop-tg-mini { display: flex; flex-direction: column; gap: 8px; box-sizing: border-box; height: 210px; padding: 8px; overflow: hidden;
            content-visibility: auto; contain-intrinsic-size: auto 210px; background: var(--c-bg); color: var(--c-ink);
            font: 400 10px/1.2 var(--c-font-b); border-bottom: 1px solid rgba(128, 128, 128, .3); }
        .shop-tg-mini * { box-sizing: border-box; min-width: 0; }
        .shop-tg-mini .mn-d { font-family: var(--c-font-d); font-weight: var(--c-weight-d); font-style: var(--c-style-d); text-transform: var(--c-case-d); }
        .shop-tg-mini .mn-head { display: flex; align-items: center; gap: 8px; height: 24px; padding: 0 8px; background: var(--c-surface);
            border: var(--c-bw) solid var(--c-line); border-radius: var(--c-radius); }
        .shop-tg-mini .mn-logo { font-size: 12px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 40%; }
        .shop-tg-mini .mn-search { flex: 1; overflow: hidden; white-space: nowrap; padding: 3px 8px; color: var(--c-muted); background: var(--c-bg);
            border: var(--c-bw) solid var(--c-line); border-radius: var(--c-radius-btn); }
        .shop-tg-mini .mn-cart { flex: none; width: 16px; height: 16px; background: var(--c-primary); border-radius: var(--c-radius-btn); }
        .shop-tg-mini .mn-hero { display: flex; flex-direction: column; align-items: flex-start; justify-content: center; gap: 6px; height: 66px; padding: 0 12px;
            background: var(--c-hero-bg); color: var(--c-hero-ink); border: var(--c-bw) solid var(--c-line); border-radius: var(--c-radius); }
        .shop-tg-mini .mn-hero-t { font-size: 15px; line-height: 1.1; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 100%; }
        .shop-tg-mini .mn-btn { padding: 3px 9px; background: var(--c-primary); color: var(--c-on-primary); border-radius: var(--c-radius-btn); }
        .shop-tg-mini .mn-tiles { display: grid; flex: 1; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 6px; }
        .shop-tg-mini .mn-tile { display: flex; flex-direction: column; gap: 3px; padding: 4px; overflow: hidden; background: var(--c-surface);
            border: var(--c-bw) solid var(--c-line); border-radius: var(--c-radius); }
        .shop-tg-mini .mn-img { flex: 1; min-height: 18px; background: var(--c-tile); border-radius: calc(var(--c-radius) / 2); position: relative; }
        .shop-tg-mini .mn-img::after { content: ""; position: absolute; top: 3px; left: 3px; width: 8px; height: 8px; background: var(--c-accent); border-radius: 999px; }
        .shop-tg-mini .mn-title { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; color: var(--c-muted); }
        .shop-tg-mini .mn-price { font-size: 11px; color: var(--c-ink); white-space: nowrap; }
        .shop-tg-mini .mn-buy { align-self: flex-start; padding: 2px 6px; background: var(--c-primary); color: var(--c-on-primary); border-radius: var(--c-radius-btn); }

        /* Preview overlay (D-210). */
        .shop-tg-ov { position: fixed; inset: 0; z-index: 40; display: flex; align-items: stretch; justify-content: center; padding: 1.5rem; background: rgba(0, 0, 0, .6); }
        .shop-tg-ov-win { display: flex; flex-direction: column; width: 100%; max-width: 1600px; overflow: hidden; border-radius: .75rem; background: #fff; box-shadow: 0 20px 40px rgba(0, 0, 0, .35); }
        .dark .shop-tg-ov-win { background: var(--gray-900); }
        .shop-tg-ov-bar { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem; padding: .5rem .75rem; border-bottom: 1px solid var(--gray-200); }
        .dark .shop-tg-ov-bar { border-color: rgba(255, 255, 255, .1); }
        .shop-tg-ov-title { display: flex; flex: 1; align-items: baseline; gap: .5rem; min-width: 0; font-weight: 600; color: var(--gray-950); }
        .dark .shop-tg-ov-title { color: #fff; }
        .shop-tg-ov-count { font-size: .75rem; font-weight: 400; color: var(--gray-500); white-space: nowrap; }
        .shop-tg-ov-name { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .shop-tg-ov-switch { display: none; }
        @media (min-width: 1024px) { .shop-tg-ov-switch { display: inline-flex; } }
        .shop-tg-ov-body { display: flex; flex: 1; min-height: 0; align-items: center; justify-content: center; background: var(--gray-100); overflow: auto; }
        .dark .shop-tg-ov-body { background: var(--gray-950); }
        .shop-tg-ov-frame { display: block; flex: 1; width: 100%; height: 100%; border: 0; background: #fff; }
        .shop-tg-ov-body.is-phone { padding: 1rem; }
        .shop-tg-ov-body.is-phone .shop-tg-ov-frame { flex: none; width: 390px; max-width: 100%; height: min(844px, 100%); border: 10px solid #1f2937; border-radius: 2.5rem; box-sizing: content-box; }
        @media (max-width: 639px) { .shop-tg-ov { padding: 0; } .shop-tg-ov-win { border-radius: 0; } }
    </style>
    @foreach ($groups as $group)
        <div>
            <h3 class="shop-tg-group-title">{{ $group['title'] }}</h3>
            <div class="shop-tg-grid shop-tg-grid--3">
                @foreach ($group['themes'] as $card)
                    <div @class(['shop-tg-card', 'shop-tg-card--active' => $card['active']])
                        data-theme-card="{{ $card['id'] }}" @if ($card['active']) data-theme-active @endif>
                        <div class="shop-tg-mini" aria-hidden="true" data-theme-miniature style="{{ $card['mini_style'] }}">
                            <div class="mn-head">
                                <span class="mn-logo mn-d">{{ $card['name'] }}</span>
                                <span class="mn-search">{{ __('themes.admin.mini.search') }}</span>
                                <span class="mn-cart"></span>
                            </div>
                            <div class="mn-hero">
                                <span class="mn-hero-t mn-d">{{ __('themes.admin.mini.hero_title') }}</span>
                                <span class="mn-btn">{{ __('themes.admin.mini.hero_button') }}</span>
                            </div>
                            <div class="mn-tiles">
                                @for ($i = 0; $i < 4; $i++)
                                    <div class="mn-tile">
                                        <span class="mn-img"></span>
                                        <span class="mn-title">{{ __('themes.admin.mini.product') }}</span>
                                        <span class="mn-price mn-d">{{ __('themes.admin.mini.price') }}</span>
                                        <span class="mn-buy">{{ __('themes.admin.mini.buy') }}</span>
                                    </div>
                                @endfor
                            </div>
                        </div>
                        <div class="shop-tg-body">
                            <div class="shop-tg-head">
                                <div class="shop-tg-name">{{ $card['name'] }}</div>
                                @if ($card['active'])
                                    <x-filament::badge color="primary" size="sm">{{ __('themes.admin.active') }}</x-filament::badge>
                                @endif
                            </div>
                            @if ($card['niche'])
                                <p class="shop-tg-meta">{{ __('themes.admin.niche', ['niche' => $card['niche']]) }}</p>
                            @endif
                            <p class="shop-tg-meta">{{ __('themes.admin.fonts_line', ['display' => $card['display'], 'body' => $card['body']]) }}</p>
                            @if ($canEdit)
                                <div class="shop-tg-actions">
                                    <x-filament::button size="sm" color="gray" type="button" wire:click="previewTheme('{{ $card['id'] }}')">{{ __('themes.admin.preview') }}</x-filament::button>
                                    <x-filament::button size="sm" type="button" wire:click="selectTheme('{{ $card['id'] }}')" :disabled="$card['active']">{{ __('themes.admin.select') }}</x-filament::button>
                                </div>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endforeach

    @if ($canEdit)
        {{-- D-210: preview overlay. wire:ignore: the whole state lives in Alpine and comes from the `theme-preview-open` event. --}}
        <div wire:ignore data-theme-preview-overlay
            x-data="{
                open: false, id: '', name: '', index: 0, total: 0, active: false, url: '', mode: 'desktop', busy: false,
                show(d) {
                    this.id = d.id; this.name = d.name; this.index = d.index; this.total = d.total; this.active = d.active; this.url = d.url; this.busy = false;
                    if (! this.open) { this.mode = 'desktop'; this.open = true; this.$nextTick(() => { this.$refs.frame.src = this.url; }); return; }
                    try { this.$refs.frame.contentWindow.location.replace(this.url); } catch (e) { this.$refs.frame.src = this.url; }
                },
                step(n) { this.busy = true; $wire.previewStep(n).then(() => { this.busy = false; }); },
                apply() { this.busy = true; $wire.applyPreview().then(() => { this.busy = false; }); },
                finish() { this.open = false; this.$refs.frame.src = 'about:blank'; },
                close() { if (! this.open) return; this.finish(); $wire.closePreview(); },
            }"
            x-on:theme-preview-open.window="show($event.detail)"
            x-on:theme-preview-close.window="finish()"
            x-on:keydown.escape.window="close()">
            <div class="shop-tg-ov" x-show="open" x-cloak style="display: none" x-on:click.self="close()">
                <div class="shop-tg-ov-win" role="dialog" aria-modal="true" x-bind:aria-label="@js(__('themes.overlay.title')) + ': ' + name" x-trap.noscroll="open">
                    <div class="shop-tg-ov-bar">
                        <div class="shop-tg-ov-title">
                            <span class="shop-tg-ov-name" x-text="name"></span>
                            <span class="shop-tg-ov-count" x-text="@js(__('themes.overlay.counter', ['index' => ':i', 'total' => ':t'])).replace(':i', index + 1).replace(':t', total)"></span>
                        </div>
                        <x-filament::button size="sm" color="gray" type="button" x-on:click="step(-1)" x-bind:disabled="busy" x-bind:title="@js(__('themes.overlay.prev'))" :aria-label="__('themes.overlay.prev')">←</x-filament::button>
                        <x-filament::button size="sm" color="gray" type="button" x-on:click="step(1)" x-bind:disabled="busy" x-bind:title="@js(__('themes.overlay.next'))" :aria-label="__('themes.overlay.next')">→</x-filament::button>
                        <span class="shop-tg-ov-switch" role="group" aria-label="{{ __('themes.overlay.device') }}" style="gap: .25rem">
                            <x-filament::button size="sm" color="gray" type="button" x-on:click="mode = 'desktop'" x-bind:aria-pressed="mode === 'desktop'">{{ __('themes.overlay.desktop') }}</x-filament::button>
                            <x-filament::button size="sm" color="gray" type="button" x-on:click="mode = 'phone'" x-bind:aria-pressed="mode === 'phone'">{{ __('themes.overlay.phone') }}</x-filament::button>
                        </span>
                        <x-filament::button size="sm" type="button" x-on:click="apply()" x-bind:disabled="busy || active">{{ __('themes.overlay.select') }}</x-filament::button>
                        <x-filament::button size="sm" color="gray" type="button" x-on:click="close()" :aria-label="__('themes.overlay.close')" :title="__('themes.overlay.close')">✕</x-filament::button>
                    </div>
                    <div class="shop-tg-ov-body" x-bind:class="mode === 'phone' ? 'is-phone' : ''">
                        <iframe x-ref="frame" class="shop-tg-ov-frame" title="{{ __('themes.overlay.frame_title') }}" data-theme-preview-frame></iframe>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
