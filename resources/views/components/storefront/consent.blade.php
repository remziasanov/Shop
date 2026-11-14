{{-- Consent checkbox (SF-71): never checked by default; the text and the link come from the legal settings. --}}
@props(['prefix' => 'f', 'link'])
@php($id = $prefix.'-consent')
<div>
    <div class="flex min-h-11 items-start gap-3">
        <input id="{{ $id }}" type="checkbox" wire:model="consent" aria-required="true" class="mt-0.5 h-5 w-5 flex-none rounded-btn border-line-heavy focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary"
               @if ($errors->has('consent')) aria-invalid="true" aria-describedby="{{ $id }}-error" @endif>
        <label for="{{ $id }}" class="text-sm">
            {{ $link['text'] }} <span class="text-danger" aria-hidden="true">*</span>
            @if (! empty($link['url']))
                <a href="{{ $link['url'] }}" target="_blank" rel="noopener" class="whitespace-nowrap text-primary underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary">{{ __('storefront_sales.checkout.consent_link') }}</a>
            @endif
        </label>
    </div>
    @error('consent')
        <p id="{{ $id }}-error" class="mt-1 text-sm text-danger">{{ $message }}</p>
    @enderror
</div>
