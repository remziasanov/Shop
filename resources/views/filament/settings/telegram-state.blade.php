@php
    /** @var array<string, mixed> $steps */
@endphp
<div class="space-y-4 text-sm" data-telegram-state>
    <dl class="grid gap-x-6 gap-y-1 sm:grid-cols-[max-content_1fr]">
        <dt class="text-gray-500">{{ __('telegram.admin.state.status') }}</dt>
        <dd class="font-medium" data-telegram-status>
            @if ($connected)
                {{ __('telegram.admin.state.connected') }}
            @elseif ($tokenSet)
                {{ __('telegram.admin.state.token_set') }}
            @else
                {{ __('telegram.admin.state.not_connected') }}
            @endif
        </dd>
        <dt class="text-gray-500">{{ __('telegram.admin.fields.bot_username') }}</dt>
        <dd>{{ $botUsername ? '@'.$botUsername : '—' }}</dd>
        <dt class="text-gray-500">{{ __('telegram.admin.fields.bot_id') }}</dt>
        <dd>{{ $botId ?? '—' }}</dd>
        <dt class="text-gray-500">{{ __('telegram.admin.state.webhook_url') }}</dt>
        <dd class="break-all">{{ $webhookUrl }}</dd>
    </dl>

    @unless ($canConnect)
        <p class="rounded border border-warning-500 p-3" data-telegram-https>{{ __('telegram.admin.https_required') }}</p>
    @endunless

    @if ($workChatError)
        <p class="rounded border border-danger-500 p-3 text-danger-600" data-work-chat-error>{{ __('telegram.admin.work_chat_error', ['error' => $workChatError]) }}</p>
    @endif

    @if ($steps !== [])
        <ul class="space-y-1" data-telegram-steps>
            @foreach ($steps as $step)
                <li class="{{ $step['ok'] ? 'text-success-600' : 'text-danger-600' }}" data-step="{{ $step['key'] }}">
                    {{ $step['ok'] ? '✔' : '✖' }} {{ __('telegram.admin.steps.'.$step['key']) }}@if ($step['detail'] !== ''): {{ $step['detail'] }}@endif
                </li>
            @endforeach
        </ul>
    @endif

    @if ($webhookError)
        <p class="text-danger-600" data-webhook-error>{{ $webhookError }}</p>
    @endif

    @if ($webhook)
        <dl class="grid gap-x-6 gap-y-1 sm:grid-cols-[max-content_1fr]" data-webhook-info>
            <dt class="text-gray-500">{{ __('telegram.admin.state.webhook_url') }}</dt>
            <dd class="break-all">{{ $webhook['url'] !== '' ? $webhook['url'] : __('telegram.admin.state.none') }}</dd>
            <dt class="text-gray-500">{{ __('telegram.admin.state.pending') }}</dt>
            <dd>{{ $webhook['pending'] }}</dd>
            <dt class="text-gray-500">{{ __('telegram.admin.state.last_error') }}</dt>
            <dd>{{ $webhook['last_error'] ? $webhook['last_error'].($webhook['last_error_at'] ? ' ('.$webhook['last_error_at'].')' : '') : __('telegram.admin.state.no_errors') }}</dd>
        </dl>
    @endif
</div>
