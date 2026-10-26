{{-- Consent checkbox (SF-71): never checked by default; the text and the link come from the legal settings. --}}
@props(['prefix' => 'f', 'link'])
@php($id = $prefix.'-consent')
<div>
    <div class="flex items-start gap-2">
        <input id="{{ $id }}" type="checkbox" wire:model="consent" aria-required="true" class="mt-1 h-4 w-4 flex-none rounded border-slate-400 focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary"
               @if ($errors->has('consent')) aria-invalid="true" aria-describedby="{{ $id }}-error" @endif>
        <label for="{{ $id }}" class="text-sm">
            {{ $link['text'] }} <span class="text-red-700" aria-hidden="true">*</span>
            @if (! empty($link['url']))
                <a href="{{ $link['url'] }}" target="_blank" rel="noopener" class="whitespace-nowrap text-primary underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary">{{ __('storefront_sales.checkout.consent_link') }}</a>
            @endif
        </label>
    </div>
    @error('consent')
        <p id="{{ $id }}-error" class="mt-1 text-sm text-red-700">{{ $message }}</p>
    @enderror
</div>
