{{-- Labelled field of a server-rendered account form (NF-31: label, aria-invalid, hint linked by aria-describedby). Errors are listed once, above the form (x-account.errors). --}}
@props(['name', 'label', 'prefix' => 'a', 'type' => 'text', 'required' => true, 'hint' => null, 'value' => null])
@php($id = $prefix.'-'.$name)
<div>
    <label for="{{ $id }}" class="mb-1 block text-sm font-medium">
        {{ $label }}
        @if ($required)
            <span class="text-red-700" aria-hidden="true">*</span>
        @endif
    </label>
    <input id="{{ $id }}" name="{{ $name }}" type="{{ $type }}" @if ($type !== 'password') value="{{ old($name, $value) }}" @endif
           @if ($required) required aria-required="true" @endif
           @if ($errors->has($name)) aria-invalid="true" @endif @if ($hint) aria-describedby="{{ $id }}-hint" @endif
           {{ $attributes->merge(['class' => 'w-full rounded border border-slate-300 px-3 py-2 focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary aria-invalid:border-red-600']) }}>
    @if ($hint)
        <p id="{{ $id }}-hint" class="mt-1 text-xs text-slate-500">{{ $hint }}</p>
    @endif
</div>
