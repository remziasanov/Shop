@props(['action', 'seconds' => 0])
{{-- «Отправить код ещё раз» with a countdown (CA-04). `seconds` is what the server still holds the pause for. The button is
     enabled in the HTML, so without JavaScript it still works (the server enforces the pause); Alpine marks it
     `aria-disabled` and blocks the submit while the countdown runs (not `disabled`, which would take it out of the Tab order). The live region is read out sparingly, not on every tick. --}}
<form method="POST" action="{{ $action }}" class="mt-4" x-on:submit="if (left > 0) $event.preventDefault()"
      x-data="resendCountdown(@js(['seconds' => max(0, (int) $seconds), 'wait' => __('account.resend.wait', ['time' => ':time']), 'ready' => __('account.resend.ready'), 'label' => __('account.resend.button')]))">
    @csrf
    <x-account.button variant="secondary" x-bind:aria-disabled="left > 0 ? 'true' : 'false'" x-bind:class="left > 0 ? 'cursor-not-allowed opacity-60' : ''">
        <span x-text="label">{{ __('account.resend.button') }}</span>
    </x-account.button>
    <span class="sr-only" aria-live="polite" aria-atomic="true" x-text="announcement"></span>
</form>
