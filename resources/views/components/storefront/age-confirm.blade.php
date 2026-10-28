{{-- AG-11: «Мне исполнилось N лет». Rendered only when the products are restricted; required, never checked by default, validated on the server. --}}
@props(['prefix' => 'f', 'level'])
@php($id = $prefix.'-age')
<div>
    <div class="flex items-start gap-2">
        <input id="{{ $id }}" type="checkbox" wire:model="ageConfirmed" aria-required="true" class="mt-1 h-4 w-4 flex-none rounded border-slate-400 focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary"
               @if ($errors->has('ageConfirmed')) aria-invalid="true" aria-describedby="{{ $id }}-error" @endif>
        <label for="{{ $id }}" class="text-sm">
            {{ __('age_gate.checkout.confirm', ['age' => $level->minAge()]) }} <span class="text-red-700" aria-hidden="true">*</span>
        </label>
    </div>
    @error('ageConfirmed')
        <p id="{{ $id }}-error" class="mt-1 text-sm text-red-700">{{ $message }}</p>
    @enderror
</div>
