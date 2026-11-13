@props(['summary' => [], 'days' => []])

<div class="space-y-6 text-sm">
    <dl class="grid grid-cols-2 gap-4 md:grid-cols-5">
        @foreach (['clicks', 'unique', 'orders', 'revenue', 'conversion'] as $key)
            <div>
                <dt class="text-gray-500 dark:text-gray-400">{{ __('promo_admin.links.stats.'.$key) }}</dt>
                <dd class="text-lg font-semibold text-gray-950 dark:text-white">{{ $summary[$key] }}</dd>
            </div>
        @endforeach
    </dl>

    <div>
        <h3 class="mb-2 font-medium text-gray-950 dark:text-white">{{ __('promo_admin.links.stats.per_day') }}</h3>
        @if (count($days) > 0)
            @php($max = max(1, ...array_column($days, 'clicks')))
            <div class="overflow-x-auto">
                <table class="w-full text-start divide-y divide-gray-200 dark:divide-white/10">
                    <thead>
                        <tr class="font-medium text-gray-950 dark:text-white">
                            <th class="px-3 py-2 text-start">{{ __('promo_admin.links.stats.day') }}</th>
                            <th class="px-3 py-2 text-start"></th>
                            <th class="px-3 py-2 text-end">{{ __('promo_admin.links.stats.clicks') }}</th>
                            <th class="px-3 py-2 text-end">{{ __('promo_admin.links.stats.unique') }}</th>
                            <th class="px-3 py-2 text-end">{{ __('promo_admin.links.stats.orders') }}</th>
                            <th class="px-3 py-2 text-end">{{ __('promo_admin.links.stats.revenue') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                        @foreach ($days as $day)
                            <tr>
                                <td class="px-3 py-2 whitespace-nowrap">{{ $day['day'] }}</td>
                                <td class="w-1/3 px-3 py-2">
                                    <div class="h-2 rounded bg-primary-500" style="width: {{ max(2, (int) round($day['clicks'] * 100 / $max)) }}%"></div>
                                </td>
                                <td class="px-3 py-2 text-end">{{ $day['clicks'] }}</td>
                                <td class="px-3 py-2 text-end">{{ $day['unique'] }}</td>
                                <td class="px-3 py-2 text-end">{{ $day['orders'] }}</td>
                                <td class="px-3 py-2 whitespace-nowrap text-end">{{ $day['revenue'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <p class="text-gray-500 dark:text-gray-400">{{ __('promo_admin.links.stats.per_day_empty') }}</p>
        @endif
    </div>
</div>
