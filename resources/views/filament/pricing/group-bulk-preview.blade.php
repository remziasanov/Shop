@props(['summary' => null, 'rows' => [], 'empty' => null])

<div class="space-y-3 text-sm">
    @if ($summary)
        <p class="font-medium text-gray-950 dark:text-white">{{ $summary }}</p>
    @endif

    @if (count($rows) > 0)
        <div class="overflow-x-auto">
            <table class="w-full text-start text-sm divide-y divide-gray-200 dark:divide-white/10">
                <thead>
                    <tr class="font-medium text-gray-950 dark:text-white">
                        <th class="px-3 py-2 text-start">{{ __('admin_pricing.preview.product') }}</th>
                        <th class="px-3 py-2 text-start">{{ __('admin_pricing.preview.sku') }}</th>
                        <th class="px-3 py-2 text-end">{{ __('admin_group_prices.bulk.before') }}</th>
                        <th class="px-3 py-2 text-end">{{ __('admin_group_prices.bulk.after') }}</th>
                        <th class="px-3 py-2 text-start"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                    @foreach ($rows as $row)
                        <tr>
                            <td class="px-3 py-2">{{ $row['name'] }}</td>
                            <td class="px-3 py-2 whitespace-nowrap text-gray-500 dark:text-gray-400">{{ $row['sku'] }}</td>
                            <td class="px-3 py-2 whitespace-nowrap text-end">{{ $row['before'] }}</td>
                            <td class="px-3 py-2 whitespace-nowrap text-end font-medium">{{ $row['after'] }}</td>
                            <td class="px-3 py-2 text-gray-500 dark:text-gray-400">{{ $row['note'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @elseif ($empty)
        <p class="text-gray-500 dark:text-gray-400">{{ $empty }}</p>
    @endif
</div>
