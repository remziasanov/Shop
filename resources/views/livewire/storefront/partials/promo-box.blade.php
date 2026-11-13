{{-- PM-13: «Есть промокод?» — collapsible field, the applied code with «Убрать» and the messages of PM-09 / PM-14 / PM-23. --}}
@php($open = $promo['code'] !== null || $promoError !== null || $promoNotice !== null || $promo['hint'] !== null)
<div class="rounded border border-slate-200 p-3 text-sm" data-promo-box>
    @if ($promo['code'] !== null)
        <div class="flex items-center justify-between gap-3">
            <p class="font-medium {{ $promo['applied'] ? 'text-emerald-800' : 'text-amber-900' }}" data-promo-applied role="status">
                @if ($promo['applied'])
                    {{ __('promo.box.applied', ['code' => $promo['code']]) }}
                @else
                    {{ __('promo.box.not_applied', ['code' => $promo['code'], 'reason' => $promo['failure']]) }}
                @endif
            </p>
            <button type="button" wire:click="removePromo" class="flex-none rounded text-primary underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary" aria-label="{{ __('promo.box.remove_label', ['code' => $promo['code']]) }}" data-promo-remove>{{ __('promo.box.remove') }}</button>
        </div>
    @endif

    @if ($promoNotice !== null)
        <p class="mt-2 text-amber-900" role="status" data-promo-notice>{{ $promoNotice }}</p>
    @endif

    @if ($promo['hint'] !== null)
        <p class="mt-2 text-slate-700" role="status" data-promo-hint>{{ $promo['hint'] }}</p>
    @endif

    <details wire:ignore.self class="{{ $promo['code'] !== null ? 'mt-2' : '' }}" @if ($promo['code'] === null && $open) open @endif>
        <summary class="cursor-pointer select-none font-medium text-primary focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary">{{ __('promo.box.toggle') }}</summary>
        <div class="mt-2 flex gap-2">
            <label for="promo-input-{{ $this->getId() }}" class="sr-only">{{ __('promo.box.label') }}</label>
            <input id="promo-input-{{ $this->getId() }}" type="text" wire:model="promoInput" wire:keydown.enter.prevent="applyPromo" maxlength="64" autocomplete="off" autocapitalize="characters" spellcheck="false"
                   placeholder="{{ __('promo.box.placeholder') }}" class="min-w-0 flex-1 rounded border border-slate-300 px-3 py-2 uppercase focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary" data-promo-input>
            <button type="button" wire:click="applyPromo" wire:loading.attr="disabled" wire:target="applyPromo" class="flex-none rounded bg-slate-800 px-4 py-2 font-medium text-white hover:opacity-90 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-800 disabled:opacity-60" data-promo-apply>{{ __('promo.box.apply') }}</button>
        </div>
        <div aria-live="assertive" role="alert">
            @if ($promoError !== null)
                <p class="mt-2 text-red-700" data-promo-error>{{ $promoError }}</p>
            @endif
        </div>
    </details>
</div>
