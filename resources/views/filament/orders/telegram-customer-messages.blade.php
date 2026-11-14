@php
    // TN-07: what was written to the customer in Telegram (customer events only; work chat messages are shown elsewhere).
    $messages = $getRecord()->telegramMessages()
        ->whereIn('kind', array_map(fn ($kind) => $kind->value, \App\Enums\CustomerTelegramEvent::kinds()))
        ->orderByDesc('id')
        ->get();
@endphp

<div class="text-sm" data-order-telegram-messages>
    @if ($messages->isEmpty())
        <p class="text-gray-500 dark:text-gray-400">{{ __('telegram_notify.order.empty') }}</p>
    @else
        <table class="w-full text-start">
            <thead>
                <tr class="text-gray-500 dark:text-gray-400">
                    <th class="py-1 pe-3 text-start font-normal">{{ __('telegram_notify.order.when') }}</th>
                    <th class="py-1 pe-3 text-start font-normal">{{ __('telegram_notify.order.event') }}</th>
                    <th class="py-1 pe-3 text-start font-normal">{{ __('telegram_notify.order.status') }}</th>
                    <th class="py-1 text-start font-normal">{{ __('telegram_notify.order.detail') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($messages as $message)
                    <tr>
                        <td class="py-1 pe-3">{{ ($message->sent_at ?? $message->created_at)?->format('d.m.Y H:i') }}</td>
                        <td class="py-1 pe-3">{{ $message->kind->label() }}</td>
                        <td class="py-1 pe-3 font-medium">{{ $message->status->label() }}</td>
                        <td class="py-1">
                            @if ($message->error !== null)
                                {{ trans()->has('telegram_notify.order.reasons.'.$message->error) ? __('telegram_notify.order.reasons.'.$message->error) : $message->error }}
                            @else
                                —
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>
