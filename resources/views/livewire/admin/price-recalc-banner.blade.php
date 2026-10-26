<div @if ($progress !== null) wire:poll.5s @endif>
    @if ($progress !== null)
        <div class="fi-price-recalc-banner" role="status" style="margin-bottom: 1rem; padding: 0.75rem 1rem; border-radius: 0.5rem; background: rgb(var(--primary-50, 239 246 255)); border: 1px solid rgb(var(--primary-200, 191 219 254));">
            <div style="font-size: 0.875rem; font-weight: 500;">
                {{ __('pricing.recalc_banner', ['done' => $progress['done'], 'total' => $progress['total']]) }}
            </div>
            <div style="margin-top: 0.5rem; height: 0.375rem; border-radius: 9999px; background: rgba(128, 128, 128, 0.25); overflow: hidden;">
                <div style="height: 100%; width: {{ $progress['percent'] }}%; background: rgb(var(--primary-600, 37 99 235));"></div>
            </div>
        </div>
    @endif
</div>
