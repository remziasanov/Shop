<x-filament-panels::page>
    <x-filament::section>
        <div class="overflow-x-auto">
            <table class="w-full text-start text-sm divide-y divide-gray-200 dark:divide-white/10">
                <thead>
                    <tr class="text-start font-medium text-gray-950 dark:text-white">
                        <th class="px-3 py-2 text-start">{{ __('system.columns.status') }}</th>
                        <th class="px-3 py-2 text-start">{{ __('system.columns.check') }}</th>
                        <th class="px-3 py-2 text-start">{{ __('system.columns.value') }}</th>
                        <th class="px-3 py-2 text-start">{{ __('system.columns.hint') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                    @foreach ($this->getResults() as $result)
                        <tr wire:key="check-{{ $result->key }}">
                            <td class="px-3 py-2 whitespace-nowrap">
                                <x-filament::badge :color="$result->status->color()">
                                    {{ $result->status->label() }}
                                </x-filament::badge>
                            </td>
                            <td class="px-3 py-2">{{ $result->label }}</td>
                            <td class="px-3 py-2">{{ $result->value }}</td>
                            <td class="px-3 py-2 text-gray-500 dark:text-gray-400">{{ $result->hint }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
