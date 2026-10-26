{{-- Labelled text field bound to a Livewire property of the same name (NF-31: label, aria-invalid, error linked by aria-describedby). --}}
@props(['name', 'label', 'prefix' => 'f', 'type' => 'text', 'required' => false, 'hint' => null, 'live' => false])
@php($id = $prefix.'-'.$name)
@php($describedBy = trim(($hint ? $id.'-hint ' : '').($errors->has($name) ? $id.'-error' : '')))
<div>
    <label for="{{ $id }}" class="mb-1 block text-sm font-medium">
        {{ $label }}
        @if ($required)
            <span class="text-red-700" aria-hidden="true">*</span>
        @else
            <span class="font-normal text-slate-500">{{ __('storefront_sales.fields.optional') }}</span>
        @endif
    </label>
    @if ($type === 'textarea')
        <textarea id="{{ $id }}" rows="3" wire:model="{{ $name }}" @if ($required) aria-required="true" @endif
                  @if ($errors->has($name)) aria-invalid="true" @endif @if ($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif
                  {{ $attributes->merge(['class' => 'w-full rounded border border-slate-300 px-3 py-2 focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary aria-invalid:border-red-600']) }}></textarea>
    @else
        <input id="{{ $id }}" type="{{ $type }}" wire:model{{ $live ? '.live' : '' }}="{{ $name }}" @if ($required) aria-required="true" @endif
               @if ($errors->has($name)) aria-invalid="true" @endif @if ($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif
               {{ $attributes->merge(['class' => 'w-full rounded border border-slate-300 px-3 py-2 focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary aria-invalid:border-red-600']) }}>
    @endif
    @if ($hint)
        <p id="{{ $id }}-hint" class="mt-1 text-xs text-slate-500">{{ $hint }}</p>
    @endif
    @error($name)
        <p id="{{ $id }}-error" class="mt-1 text-sm text-red-700">{{ $message }}</p>
    @enderror
</div>
