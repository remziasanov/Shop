@php
    // TS-01: the staff messages about this request (kind staff_new_order): the work chat has no account, a personal one has.
    $messages = $getRecord()->telegramMessages()
        ->where('kind', \App\Enums\TelegramMessageKind::StaffNewOrder->value)
        ->latest('id')
        ->limit(20)
        ->get();
@endphp
<table class="w-full text-sm" data-staff-telegram-messages>
    <thead>
        <tr class="text-left text-gray-500">
            <th class="py-1 pr-4">{{ __('telegram_staff.admin.when') }}</th>
            <th class="py-1 pr-4">{{ __('telegram_staff.admin.where') }}</th>
            <th class="py-1">{{ __('telegram_staff.admin.status') }}</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($messages as $message)
            <tr>
                <td class="py-1 pr-4">{{ ($message->sent_at ?? $message->created_at)?->format('d.m.Y H:i') }}</td>
                <td class="py-1 pr-4">{{ $message->telegram_account_id === null ? __('telegram_staff.admin.chat') : __('telegram_staff.admin.personal') }}</td>
                <td class="py-1">{{ $message->status->label() }}</td>
            </tr>
        @endforeach
    </tbody>
</table>
