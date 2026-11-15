{{-- PV-03, PV-04, PV-13: the phone verification step of a Livewire form that uses App\Livewire\Storefront\Concerns\VerifiesPhone. Shows the lock of an unverified account (registration «обязательно») or the code step. MB-06: numeric keyboard and one-time-code autofill. --}}
@props(['active' => false, 'method' => '', 'canSms' => false, 'resendAt' => 0, 'phone' => '', 'locked' => false, 'prefix' => 'pv'])
@if ($locked)
    <p class="rounded-btn border border-warn-line bg-warn-wash p-3 text-sm text-warn-ink" data-phone-locked>
        {{ __('phone_verification.screen.locked') }}
        <a href="{{ route('customer.phone') }}" class="font-medium underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary">{{ __('phone_verification.screen.locked_link') }}</a>
    </p>
@endif
@if ($active)
    <div class="space-y-3 rounded-card border border-primary p-4" data-phone-verify role="group" aria-labelledby="{{ $prefix }}-title">
        <h3 id="{{ $prefix }}-title" class="font-body text-base font-semibold">{{ __('phone_verification.screen.title') }}</h3>
        <p class="text-sm text-ink-high" data-phone-verify-text>
            {{ __($method === 'sms' ? 'phone_verification.screen.sms' : 'phone_verification.screen.flash_call') }}
            <span class="text-ink-mid">{{ \App\Support\PiiMask::phone($phone) }}</span>
        </p>

        <div>
            <label for="{{ $prefix }}-code" class="mb-1 block text-sm font-medium">{{ __('phone_verification.screen.code_label') }}</label>
            <input id="{{ $prefix }}-code" type="text" wire:model="pvCode" wire:keydown.enter.prevent="confirmPhoneCode"
                   inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]*" maxlength="4" aria-required="true"
                   @if ($errors->has('pvCode')) aria-invalid="true" aria-describedby="{{ $prefix }}-code-error" @endif
                   class="w-32 rounded-btn border border-line-strong px-3 py-2 text-center text-lg tracking-widest focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary aria-invalid:border-danger-solid">
            @error('pvCode')
                <p id="{{ $prefix }}-code-error" class="mt-1 text-sm text-danger" data-field-error role="alert">{{ $message }}</p>
            @enderror
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <button type="button" wire:click="confirmPhoneCode" wire:loading.attr="disabled" wire:target="confirmPhoneCode"
                    class="min-h-11 rounded-btn bg-primary px-5 py-2 font-medium text-on-primary hover:opacity-90 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:opacity-60" data-phone-confirm>{{ __('phone_verification.screen.confirm') }}</button>
            <button type="button" wire:click="cancelPhoneVerification"
                    class="min-h-11 rounded-btn border border-line-strong px-4 py-2 text-sm hover:bg-surface-2 focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary" data-phone-cancel>{{ __('phone_verification.screen.change_number') }}</button>
        </div>

        {{-- The timer counts down in the browser; the server enforces the pause (PV-06) and the SMS rule (PV-04) anyway. --}}
        <div wire:key="{{ $prefix }}-timer-{{ $resendAt }}" x-data="{ left: Math.max(0, {{ (int) $resendAt }} - Math.floor(Date.now() / 1000)), init() { const t = setInterval(() => { if (this.left > 0) { this.left-- } else { clearInterval(t) } }, 1000) } }" class="flex flex-wrap items-center gap-3 text-sm" data-phone-timer>
            <span class="text-ink-mid" x-show="left > 0" x-text="@js(__('phone_verification.screen.resend_in', ['seconds' => ':s'])).replace(':s', left)" aria-live="off">{{ __('phone_verification.screen.resend_in', ['seconds' => max(0, $resendAt - now()->getTimestamp())]) }}</span>
            <button type="button" x-show="left <= 0" x-cloak wire:click="resendPhoneCall"
                    class="min-h-11 text-primary underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary" data-phone-resend>{{ __('phone_verification.screen.resend') }}</button>
            @if ($canSms)
                <button type="button" x-show="left <= 0" x-cloak wire:click="requestPhoneSms"
                        class="min-h-11 text-primary underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary" data-phone-sms>{{ __('phone_verification.screen.get_sms') }}</button>
            @endif
        </div>
    </div>
@endif
