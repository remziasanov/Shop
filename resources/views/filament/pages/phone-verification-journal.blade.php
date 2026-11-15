<x-filament-panels::page>
    @php($totals = $this->monthTotals())
    <x-filament::section :heading="__('phone_verification.journal.month_title', ['month' => now()->translatedFormat('F Y')])">
        <dl class="grid gap-4 sm:grid-cols-2" data-phone-month-totals>
            <div>
                <dt class="text-sm text-gray-500 dark:text-gray-400">{{ __('phone_verification.journal.month_count') }}</dt>
                <dd class="text-2xl font-semibold text-gray-950 dark:text-white" data-total-count>{{ $totals['count'] }}</dd>
            </div>
            <div>
                <dt class="text-sm text-gray-500 dark:text-gray-400">{{ __('phone_verification.journal.month_cost') }}</dt>
                <dd class="text-2xl font-semibold text-gray-950 dark:text-white" data-total-cost>{{ $totals['cost'] }} ₽</dd>
            </div>
        </dl>
        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ __('phone_verification.journal.note', ['days' => \App\Filament\Pages\PhoneVerificationJournal::DAYS]) }}</p>
    </x-filament::section>

    {{ $this->table }}
</x-filament-panels::page>
