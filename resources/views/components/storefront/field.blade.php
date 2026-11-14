{{-- Labelled text field bound to a Livewire property of the same name (NF-31: label, aria-invalid, error linked by aria-describedby; MB-06: a number field gets inputmode=numeric). --}}
@props(['name', 'label', 'prefix' => 'f', 'type' => 'text', 'required' => false, 'hint' => null, 'live' => false])
@php($id = $prefix.'-'.$name)
@php($describedBy = trim(($hint ? $id.'-hint ' : '').($errors->has($name) ? $id.'-error' : '')))
<div>
    <label for="{{ $id }}" class="mb-1 block text-sm font-medium">
        {{ $label }}
        @if ($required)
            <span class="text-danger" aria-hidden="true">*</span>
        @else
            <span class="font-normal text-muted">{{ __('storefront_sales.fields.optional') }}</span>
        @endif
    </label>
    @if ($type === 'textarea')
        <textarea id="{{ $id }}" rows="3" wire:model="{{ $name }}" @if ($required) aria-required="true" @endif
                  @if ($errors->has($name)) aria-invalid="true" @endif @if ($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif
                  {{ $attributes->merge(['class' => 'w-full rounded-btn border border-line-strong px-3 py-2 focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary aria-invalid:border-danger-solid']) }}></textarea>
    @else
        <input id="{{ $id }}" type="{{ $type }}" wire:model{{ $live ? '.live' : '' }}="{{ $name }}" @if ($required) aria-required="true" @endif
               @if ($type === 'number' && ! $attributes->has('inputmode')) inputmode="numeric" @endif
               @if ($errors->has($name)) aria-invalid="true" @endif @if ($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif
               {{ $attributes->merge(['class' => 'w-full rounded-btn border border-line-strong px-3 py-2 focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary aria-invalid:border-danger-solid']) }}>
    @endif
    @if ($hint)
        <p id="{{ $id }}-hint" class="mt-1 text-xs text-muted">{{ $hint }}</p>
    @endif
    @error($name)
        <p id="{{ $id }}-error" class="mt-1 text-sm text-danger">{{ $message }}</p>
    @enderror
</div>
