{{-- AG-11: «Мне исполнилось N лет». Rendered only when the products are restricted; required, never checked by default, validated on the server. --}}
@props(['prefix' => 'f', 'level'])
@php($id = $prefix.'-age')
<div>
    <div class="flex min-h-11 items-start gap-3">
        <input id="{{ $id }}" type="checkbox" wire:model="ageConfirmed" aria-required="true" class="mt-0.5 h-5 w-5 flex-none rounded-btn border-line-heavy focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary"
               @if ($errors->has('ageConfirmed')) aria-invalid="true" aria-describedby="{{ $id }}-error" @endif>
        <label for="{{ $id }}" class="text-sm">
            {{ __('age_gate.checkout.confirm', ['age' => $level->minAge()]) }} <span class="text-danger" aria-hidden="true">*</span>
        </label>
    </div>
    @error('ageConfirmed')
        <p id="{{ $id }}-error" class="mt-1 text-sm text-danger">{{ $message }}</p>
    @enderror
</div>
