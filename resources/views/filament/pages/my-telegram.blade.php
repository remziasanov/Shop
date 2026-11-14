<x-filament-panels::page>
    <x-filament::section>
        @if ($this->isLinked())
            <p data-staff-telegram-linked>{{ __('telegram.staff.connected') }}: {{ $this->linkedUsername() }}</p>
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
