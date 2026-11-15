<x-filament-panels::page>
    <x-filament::section>
        @if ($this->isLinked())
            <p data-staff-telegram-linked>{{ __('telegram.staff.connected') }}: {{ $this->linkedUsername() }}</p>
            <label class="mt-4 flex items-start gap-3" data-staff-personal-notify>
                <input type="checkbox" wire:model.live="personalNotify" class="mt-1 rounded border-gray-300">
                <span>
                    <span class="block font-medium">{{ __('telegram_staff.personal.toggle') }}</span>
                    <span class="block text-sm text-gray-500">{{ __('telegram_staff.personal.toggle_help') }}</span>
                </span>
            </label>
        @elseif (! $this->botAvailable())
            <p data-staff-telegram-unavailable>{{ __('telegram.staff.unavailable') }}</p>
        @elseif ($link)
            <p>{{ __('telegram.staff.link_ready') }}</p>
            <p class="mt-3">
                <a href="{{ $link }}" target="_blank" rel="noopener noreferrer" class="font-medium text-primary-600 underline" data-staff-telegram-link>{{ __('telegram.staff.link_open') }}</a>
            </p>
        @else
            <p>{{ __('telegram.staff.title') }}</p>
        @endif
    </x-filament::section>
</x-filament-panels::page>
