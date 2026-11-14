{{-- TS-06: the list of the «Рабочее место». Theme tokens only (TH-06). --}}
<div class="mx-auto w-full max-w-3xl" data-staff-list>
    <h1 class="text-xl font-bold">{{ __('telegram_staff.workplace.title') }}</h1>

    <div class="mt-3 flex gap-2 overflow-x-auto pb-1" role="tablist" data-staff-tabs>
        @foreach (\App\Livewire\Staff\WorkplaceList::TABS as $key)
            <button type="button" role="tab" wire:click="setTab('{{ $key }}')" aria-selected="{{ $tab === $key ? 'true' : 'false' }}" data-tab="{{ $key }}"
                    class="inline-flex min-h-11 shrink-0 items-center gap-2 rounded-btn border px-4 text-sm font-medium {{ $tab === $key ? 'border-primary bg-primary text-on-primary' : 'border-line-strong bg-surface text-ink' }}">
                {{ __('telegram_staff.workplace.tabs.'.$key) }}
                @if ($key === 'new' && $newCount > 0)
                    <span class="rounded-btn bg-accent px-2 text-xs text-on-accent" data-new-count>{{ $newCount }}</span>
                @endif
            </button>
        @endforeach
    </div>

    <form wire:submit.prevent class="mt-3">
        <label class="sr-only" for="staff-search">{{ __('telegram_staff.workplace.search') }}</label>
        <input id="staff-search" type="search" wire:model.live.debounce.400ms="search" placeholder="{{ __('telegram_staff.workplace.search') }}" maxlength="100" autocomplete="off"
               class="min-h-11 w-full rounded-btn border border-line-strong bg-surface px-3 text-ink">
    </form>

    <ul class="mt-4 grid gap-3" data-staff-orders>
        @forelse ($orders as $order)
            <li wire:key="order-{{ $order->id }}">
                <a href="{{ route('tg.staff.order', $order->id) }}" class="block rounded-card border border-line bg-surface p-4 hover:border-primary" data-order="{{ $order->id }}">
                    <span class="flex items-start justify-between gap-3">
                        <span class="font-semibold">{{ $order->type->label() }} {{ $order->number }}</span>
                        <span class="shrink-0 rounded-btn bg-surface-2 px-2 py-0.5 text-xs text-ink-mid">{{ $order->status->label($order->type) }}</span>
                    </span>
                    <span class="mt-1 block text-sm text-ink-mid">{{ $order->customer_name }} · {{ $order->customer_phone }}</span>
                    <span class="mt-1 flex justify-between gap-3 text-sm text-ink-soft">
                        <span>
                            @if ($order->type === \App\Enums\OrderType::Reservation && $order->reserved_until !== null)
                                {{ __('telegram_staff.workplace.reserved_until') }}: {{ $order->reserved_until->format('d.m H:i') }}
                            @else
                                {{ $order->created_at?->format('d.m.Y H:i') }}
                            @endif
                        </span>
                        @if ($order->total !== null)
                            <span class="font-medium text-ink">{{ \App\Support\Money::format(\App\Support\Money::fromRubles($order->total)) }}</span>
                        @endif
                    </span>
                </a>
            </li>
        @empty
            <li class="rounded-card border border-line bg-surface p-4 text-ink-mid" data-staff-empty>{{ __('telegram_staff.workplace.empty') }}</li>
        @endforelse
    </ul>

    @if ($hasMore)
        <p class="mt-4 text-center">
            <button type="button" wire:click="more" class="min-h-11 rounded-btn border border-line-strong bg-surface px-5 font-medium" data-staff-more>{{ __('telegram_staff.workplace.more') }}</button>
        </p>
    @endif
</div>
