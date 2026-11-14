<div class="space-y-2 text-sm" data-telegram-startapp>
    <p>{{ __('telegram.admin.startapp.intro') }}</p>
    <ol class="list-decimal space-y-1 pl-5">
        <li>{{ __('telegram.admin.startapp.step1') }}</li>
        <li>{{ __('telegram.admin.startapp.step2') }}</li>
        <li>{{ __('telegram.admin.startapp.step3', ['url' => $baseUrl]) }}</li>
        <li>{{ __('telegram.admin.startapp.step4') }}</li>
    </ol>
    <p class="break-all font-mono">{{ $baseUrl }}</p>
    <p>
        @if ($checkUrl)
            <a href="{{ $checkUrl }}" target="_blank" rel="noopener noreferrer" class="font-medium text-primary-600 underline" data-startapp-check>{{ __('telegram.admin.startapp.check') }}</a>
        @else
            <span class="text-gray-500">{{ __('telegram.admin.startapp.connect_first') }}</span>
        @endif
    </p>
    <p class="{{ $verified ? 'text-success-600' : 'text-gray-500' }}" data-startapp-mark>{{ $verified ? '✔ '.__('telegram.admin.startapp.verified') : __('telegram.admin.startapp.not_verified') }}</p>
</div>
