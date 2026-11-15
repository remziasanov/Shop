{{-- Labelled field of a server-rendered account form (NF-31: label, aria-invalid, hint linked by aria-describedby). The error stands under the field (MB-06) and is also listed above the form (x-account.errors). --}}
@props(['name', 'label', 'prefix' => 'a', 'type' => 'text', 'required' => true, 'hint' => null, 'value' => null, 'form' => null])
{{-- `form` scopes old input and errors to one of several forms on a page (hidden `_form` field): the others keep their stored values. --}}
@php($id = $prefix.'-'.$name)
@php($active = $form === null || old('_form') === $form)
@php($hasError = $active && $errors->has($name))
@php($describedBy = trim(($hint ? $id.'-hint ' : '').($hasError ? $id.'-error' : '')))
<div>
    <label for="{{ $id }}" class="mb-1 block text-sm font-medium">
        {{ $label }}
        @if ($required)
            <span class="text-danger" aria-hidden="true">*</span>
        @endif
    </label>
    <input id="{{ $id }}" name="{{ $name }}" type="{{ $type }}" @if ($type !== 'password') value="{{ $active ? old($name, $value) : $value }}" @endif
           @if ($required) required aria-required="true" @endif
           @if ($hasError) aria-invalid="true" @endif @if ($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif
           {{ $attributes->merge(['class' => 'w-full rounded-btn border border-line-strong px-3 py-2 focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary aria-invalid:border-danger-solid']) }}>
    @if ($hint)
        <p id="{{ $id }}-hint" class="mt-1 text-xs text-muted">{{ $hint }}</p>
    @endif
    {{-- MB-06: the error stands under its field (the list above the form stays for screen readers and the page scrolls to the first field with an error). --}}
    @if ($hasError)
        <p id="{{ $id }}-error" class="mt-1 text-sm text-danger" data-field-error-text>{{ $errors->first($name) }}</p>
    @endif
</div>
