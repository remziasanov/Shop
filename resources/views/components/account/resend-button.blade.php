@props(['action', 'seconds' => 0])
{{-- «Отправить код ещё раз» with a countdown (CA-04). `seconds` is what the server still holds the pause for. The button is
     enabled in the HTML, so without JavaScript it still works (the server enforces the pause); Alpine disables it while the
     countdown runs. The live region is read out sparingly, not on every tick. --}}
<form method="POST" action="{{ $action }}" class="mt-4"
      x-data="resendCountdown(@js(['seconds' => max(0, (int) $seconds), 'wait' => __('account.resend.wait', ['time' => ':time']), 'ready' => __('account.resend.ready'), 'label' => __('account.resend.button')]))">
    @csrf
    <x-account.button variant="secondary" x-bind:disabled="left > 0" x-bind:class="left > 0 ? 'cursor-not-allowed opacity-60' : ''">
        <span x-text="label">{{ __('account.resend.button') }}</span>
    </x-account.button>
    <span class="sr-only" aria-live="polite" aria-atomic="true" x-text="announcement"></span>
</form>
