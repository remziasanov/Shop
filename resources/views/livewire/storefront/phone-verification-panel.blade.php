<div class="space-y-4" data-phone-panel>
    <p class="text-sm text-ink-mid" data-phone-panel-intro>
        {{ __('phone_verification.screen.intro_'.$purpose->value, ['phone' => \App\Support\PiiMask::phone($phone)]) }}
    </p>

    @error('phone')
        <p class="rounded-btn border border-danger-line bg-danger-wash px-3 py-2 text-sm text-danger-ink" role="alert" data-field-error>{{ $message }}</p>
    @enderror

    <x-storefront.phone-verify prefix="pp-pv" :active="$pvActive" :method="$pvMethod" :can-sms="$pvCanSms" :resend-at="$pvResendAt" :phone="$pvPhone" />

    @unless ($pvActive)
        <div class="flex flex-wrap gap-2">
            <button type="button" wire:click="startVerification" wire:loading.attr="disabled" wire:target="startVerification"
                    class="min-h-11 rounded-btn bg-primary px-6 py-2 font-medium text-on-primary hover:opacity-90 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:opacity-60" data-phone-start>{{ __('phone_verification.screen.start') }}</button>
            @if ($parked)
                <button type="button" wire:click="discard" class="min-h-11 rounded-btn border border-line-strong px-4 py-2 hover:bg-surface-2 focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary" data-phone-discard>{{ __('phone_verification.screen.discard') }}</button>
            @else
                <a href="{{ route('account') }}" class="inline-flex min-h-11 items-center px-2 text-sm text-primary underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary">{{ __('phone_verification.screen.later') }}</a>
            @endif
        </div>
    @endunless
</div>
