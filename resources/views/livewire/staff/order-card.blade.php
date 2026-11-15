{{-- TS-06: the card of a request in the «Рабочее место». Theme tokens only (TH-06). --}}
<div class="mx-auto w-full max-w-3xl" data-staff-card>
    <p><a href="{{ route('tg.staff.home') }}" class="text-sm text-primary" data-staff-back>← {{ __('telegram_staff.workplace.back') }}</a></p>

    <div class="mt-2 flex items-start justify-between gap-3">
        <h1 class="text-xl font-bold">{{ $order->type->label() }} {{ $order->number }}</h1>
        <span class="shrink-0 rounded-btn bg-surface-2 px-2 py-1 text-sm text-ink-mid" data-staff-status>{{ $order->status->label($order->type) }}</span>
    </div>

    @if ($notice)
        <p role="status" class="mt-3 rounded-card border p-3 {{ $noticeError ? 'border-danger-line bg-danger-wash text-danger-ink' : 'border-success-line bg-success-wash text-success-ink' }}" data-staff-notice>{{ $notice }}</p>
    @endif

    <section class="mt-4 rounded-card border border-line bg-surface p-4" aria-label="{{ __('telegram_staff.workplace.customer') }}">
        <p class="font-semibold">{{ $order->customer_name }}</p>
        <p class="mt-1 text-sm text-ink-mid">{{ __('telegram_staff.workplace.channel') }}: {{ $order->channel->label() }}</p>
        <p class="mt-3">
            <a href="tel:{{ preg_replace('/[^\d+]/', '', $order->customer_phone) }}" class="inline-flex min-h-11 items-center rounded-btn bg-primary px-4 font-medium text-on-primary" data-staff-call>{{ __('telegram_staff.workplace.call') }}: {{ $order->customer_phone }}</a>
        </p>
        @if ($order->customer_comment)
            <p class="mt-3 text-sm"><span class="text-ink-mid">{{ __('telegram_staff.workplace.customer_comment') }}:</span> <span class="whitespace-pre-line">{{ $order->customer_comment }}</span></p>
        @endif
        @if ($order->type === \App\Enums\OrderType::Reservation && $order->reserved_until !== null)
            <p class="mt-3 text-sm" data-staff-until>{{ __('telegram_staff.workplace.reserved_until') }}: <b>{{ $order->reserved_until->format('d.m.Y H:i') }}</b></p>
        @endif
    </section>

    <section class="mt-4 rounded-card border border-line bg-surface p-4" aria-labelledby="staff-items">
        <h2 id="staff-items" class="font-semibold">{{ __('telegram_staff.workplace.items') }}</h2>
        <ul class="mt-2 divide-y divide-line text-sm">
            @foreach ($order->items as $item)
                <li class="flex justify-between gap-3 py-2">
                    <span>{{ $item->displayName() }} × {{ $item->qty }}</span>
                    @if ($item->total !== null)
                        <span class="shrink-0">{{ \App\Support\Money::format(\App\Support\Money::fromRubles($item->total)) }}</span>
                    @endif
                </li>
            @endforeach
        </ul>
        @if ($order->total !== null)
            <p class="mt-3 flex justify-between font-semibold"><span>{{ __('telegram_staff.workplace.total') }}</span><span>{{ \App\Support\Money::format(\App\Support\Money::fromRubles($order->total)) }}</span></p>
        @endif
    </section>

    <section class="mt-4 rounded-card border border-line bg-surface p-4" aria-labelledby="staff-actions">
        <h2 id="staff-actions" class="font-semibold">{{ __('telegram_staff.workplace.actions') }}</h2>
        @if (! $canUpdate)
            <p class="mt-2 text-sm text-ink-mid" data-staff-view-only>{{ __('telegram_staff.workplace.view_only') }}</p>
        @elseif ($actions === [])
            <p class="mt-2 text-sm text-ink-mid">{{ __('telegram_staff.workplace.no_actions') }}</p>
        @else
            <div class="mt-3 flex flex-wrap gap-2">
                @foreach ($actions as $action)
                    @continue($action === \App\Enums\StaffOrderAction::Extend)
                    <button type="button" wire:click="act('{{ $action->value }}')" wire:loading.attr="disabled" data-action="{{ $action->value }}"
                            class="min-h-11 rounded-btn border px-4 font-medium {{ $action->isDangerous() ? 'border-danger-line bg-danger-wash text-danger-ink' : 'border-line-strong bg-surface text-ink' }}">
                        {{ $action->isDangerous() && $armed === $action->value ? __('telegram_staff.workplace.cancel_sure') : $action->label($order->type) }}
                    </button>
                @endforeach
            </div>
            @if (in_array(\App\Enums\StaffOrderAction::Extend, $actions, true))
                <form wire:submit="act('ex')" class="mt-3 flex items-end gap-2">
                    <label class="text-sm">
                        <span class="block text-ink-mid">{{ __('telegram_staff.workplace.extend_hours') }}</span>
                        <input type="number" min="1" max="2160" wire:model="extendHours" class="min-h-11 w-24 rounded-btn border border-line-strong bg-surface px-3 text-ink" data-extend-hours>
                    </label>
                    <button type="submit" class="min-h-11 rounded-btn border border-line-strong bg-surface px-4 font-medium" data-action="ex">{{ __('telegram_staff.workplace.extend') }}</button>
                </form>
            @endif
        @endif
    </section>

    <section class="mt-4 rounded-card border border-line bg-surface p-4" aria-labelledby="staff-comment">
        <h2 id="staff-comment" class="font-semibold">{{ __('telegram_staff.workplace.manager_comment') }}</h2>
        @if ($canUpdate)
            <form wire:submit="saveComment" class="mt-2">
                <textarea wire:model="managerComment" rows="3" maxlength="5000" class="w-full rounded-btn border border-line-strong bg-surface p-3 text-ink" data-manager-comment></textarea>
                <button type="submit" class="mt-2 min-h-11 rounded-btn border border-line-strong bg-surface px-4 font-medium" data-save-comment>{{ __('telegram_staff.workplace.manager_comment_save') }}</button>
            </form>
        @else
            <p class="mt-2 whitespace-pre-line text-sm">{{ $order->manager_comment ?: '—' }}</p>
        @endif
    </section>

    <section class="mt-4 rounded-card border border-line bg-surface p-4" aria-labelledby="staff-history">
        <h2 id="staff-history" class="font-semibold">{{ __('telegram_staff.workplace.history') }}</h2>
        <ul class="mt-2 divide-y divide-line text-sm" data-staff-history>
            @foreach ($order->statusHistory->reverse() as $row)
                <li class="py-2">
                    <span class="text-ink-mid">{{ $row->created_at->format('d.m H:i') }}</span>
                    {{ $row->from_status?->label($order->type) ?? '—' }} → {{ $row->to_status->label($order->type) }}
                    <span class="text-ink-mid">· {{ $row->user->name ?? $row->source->label() }} ({{ $row->source->label() }})</span>
                    @if ($row->comment)
                        <span class="block text-ink-soft">{{ $row->comment }}</span>
                    @endif
                </li>
            @endforeach
        </ul>
    </section>

    <section class="mt-4 rounded-card border border-line bg-surface p-4" aria-labelledby="staff-messages">
        <h2 id="staff-messages" class="font-semibold">{{ __('telegram_staff.workplace.messages') }}</h2>
        <ul class="mt-2 text-sm" data-staff-messages>
            @forelse ($messages as $message)
                <li class="py-1">{{ $message->created_at?->format('d.m H:i') }} · {{ $message->kind->label() }} · {{ $message->status->label() }}</li>
            @empty
                <li class="text-ink-mid">{{ __('telegram_staff.workplace.no_messages') }}</li>
            @endforelse
        </ul>
    </section>

    <p class="mt-4 mb-6">
        <a href="{{ $adminUrl }}" target="_blank" rel="noopener" data-tg-browser class="inline-flex min-h-11 items-center rounded-btn border border-line-strong bg-surface px-4 font-medium" data-staff-admin>{{ __('telegram_staff.workplace.open_admin') }}</a>
    </p>
</div>
