@props(['preview' => []])

<div class="space-y-4 text-sm">
    @if (! empty($preview['warnings']))
        <div class="space-y-1 rounded-lg bg-warning-50 p-3 text-warning-700 dark:bg-warning-400/10 dark:text-warning-400">
            @foreach ($preview['warnings'] as $warning)
                <p>{{ $warning }}</p>
            @endforeach
        </div>
    @endif

    <div class="overflow-x-auto">
        <table class="w-full text-start divide-y divide-gray-200 dark:divide-white/10">
            <thead>
                <tr class="font-medium text-gray-950 dark:text-white">
                    <th class="px-3 py-2 text-start">{{ __('order_edit.preview.item') }}</th>
                    <th class="px-3 py-2 text-start">{{ __('order_edit.preview.was') }}</th>
                    <th class="px-3 py-2 text-start">{{ __('order_edit.preview.will') }}</th>
                    <th class="px-3 py-2 text-start">{{ __('order_edit.preview.stock') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                @foreach (($preview['rows'] ?? []) as $row)
                    <tr @class(['text-gray-400 dark:text-gray-500' => $row['kind'] === 'same'])>
                        <td class="px-3 py-2">{{ $row['name'] }}</td>
                        <td class="px-3 py-2 whitespace-nowrap">{{ $row['was'] }}</td>
                        <td @class(['px-3 py-2 whitespace-nowrap', 'font-medium' => $row['kind'] !== 'same', 'text-danger-600 dark:text-danger-400' => $row['kind'] === 'removed'])>{{ $row['will'] }}</td>
                        <td class="px-3 py-2">{{ $row['stock'] ?? __('order_edit.preview.no_stock_change') }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    @if (! empty($preview['fields']))
        <div>
            <p class="mb-1 font-medium text-gray-950 dark:text-white">{{ __('order_edit.preview.fields') }}</p>
            <table class="w-full text-start divide-y divide-gray-200 dark:divide-white/10">
                <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                    @foreach ($preview['fields'] as $field)
                        <tr>
                            <td class="px-3 py-1 text-gray-500 dark:text-gray-400">{{ $field['title'] }}</td>
                            <td class="px-3 py-1">{{ $field['was'] }}</td>
                            <td class="px-3 py-1 font-medium">{{ $field['will'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <table class="w-full text-start">
        <tbody>
            @foreach (($preview['totals'] ?? []) as $total)
                <tr @class(['font-semibold text-gray-950 dark:text-white' => $total['bold']])>
                    <td class="px-3 py-1">{{ $total['title'] }}</td>
                    <td class="px-3 py-1 whitespace-nowrap">{{ $total['was'] }}</td>
                    <td class="px-3 py-1 whitespace-nowrap">{{ $total['will'] }}</td>
                </tr>
            @endforeach
            <tr class="font-semibold text-gray-950 dark:text-white">
                <td class="px-3 py-1" colspan="2">{{ __('order_edit.preview.total_delta') }}</td>
                <td class="px-3 py-1 whitespace-nowrap">{{ $preview['delta'] ?? '0' }}</td>
            </tr>
        </tbody>
    </table>
</div>
