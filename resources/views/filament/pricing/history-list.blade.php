@props(['rows' => [], 'url' => null])

<div class="space-y-3 text-sm">
    @if (count($rows) > 0)
        <div class="overflow-x-auto">
            <table class="w-full text-start text-sm divide-y divide-gray-200 dark:divide-white/10">
                <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                    @foreach ($rows as $row)
                        <tr>
                            <td class="px-3 py-2 whitespace-nowrap text-gray-500 dark:text-gray-400">{{ $row['date'] }}</td>
                            <td class="px-3 py-2">{{ $row['field'] }}</td>
                            <td class="px-3 py-2 whitespace-nowrap">{{ $row['change'] }}</td>
                            <td class="px-3 py-2 text-gray-500 dark:text-gray-400">{{ $row['source'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @else
        <p class="text-gray-500 dark:text-gray-400">{{ __('admin_pricing.history.empty') }}</p>
    @endif

    @if ($url)
        <a href="{{ $url }}" class="font-medium text-primary-600 hover:underline dark:text-primary-400">{{ __('admin_pricing.history.open_all') }}</a>
    @endif
</div>
