@props(['summary' => [], 'days' => [], 'orders' => []])

<div class="space-y-6 text-sm">
    <dl class="grid grid-cols-2 gap-4 md:grid-cols-5">
        @foreach ([
            'stats_uses' => $summary['uses'],
            'stats_discount' => $summary['discount'],
            'stats_orders' => $summary['orders'],
            'stats_revenue' => $summary['revenue'],
            'stats_average' => $summary['average'],
        ] as $key => $value)
            <div>
                <dt class="text-gray-500 dark:text-gray-400">{{ __('promo_admin.codes.view.'.$key) }}</dt>
                <dd class="text-lg font-semibold text-gray-950 dark:text-white">{{ $value }}</dd>
            </div>
        @endforeach
    </dl>

    <div>
        <h3 class="mb-2 font-medium text-gray-950 dark:text-white">{{ __('promo_admin.codes.view.per_day') }}</h3>
        @if (count($days) > 0)
            @php($max = max(1, ...array_column($days, 'uses')))
            <div class="overflow-x-auto">
                <table class="w-full text-start divide-y divide-gray-200 dark:divide-white/10">
                    <thead>
                        <tr class="font-medium text-gray-950 dark:text-white">
                            <th class="px-3 py-2 text-start">{{ __('promo_admin.codes.view.day') }}</th>
                            <th class="px-3 py-2 text-start"></th>
                            <th class="px-3 py-2 text-end">{{ __('promo_admin.codes.view.uses') }}</th>
                            <th class="px-3 py-2 text-end">{{ __('promo_admin.codes.view.discount') }}</th>
                            <th class="px-3 py-2 text-end">{{ __('promo_admin.codes.view.orders') }}</th>
                            <th class="px-3 py-2 text-end">{{ __('promo_admin.codes.view.revenue') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                        @foreach ($days as $day)
                            <tr>
                                <td class="px-3 py-2 whitespace-nowrap">{{ $day['day'] }}</td>
                                <td class="w-1/3 px-3 py-2">
                                    <div class="h-2 rounded bg-primary-500" style="width: {{ max(2, (int) round($day['uses'] * 100 / $max)) }}%"></div>
                                </td>
                                <td class="px-3 py-2 text-end">{{ $day['uses'] }}</td>
                                <td class="px-3 py-2 whitespace-nowrap text-end">{{ $day['discount'] }}</td>
                                <td class="px-3 py-2 text-end">{{ $day['orders'] }}</td>
                                <td class="px-3 py-2 whitespace-nowrap text-end">{{ $day['revenue'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <p class="text-gray-500 dark:text-gray-400">{{ __('promo_admin.codes.view.per_day_empty') }}</p>
        @endif
    </div>

    <div>
        <h3 class="mb-2 font-medium text-gray-950 dark:text-white">{{ __('promo_admin.codes.view.recent') }}</h3>
        @if (count($orders) > 0)
            <div class="overflow-x-auto">
                <table class="w-full text-start divide-y divide-gray-200 dark:divide-white/10">
                    <thead>
                        <tr class="font-medium text-gray-950 dark:text-white">
                            <th class="px-3 py-2 text-start">{{ __('promo_admin.codes.view.order') }}</th>
                            <th class="px-3 py-2 text-start">{{ __('promo_admin.codes.view.date') }}</th>
                            <th class="px-3 py-2 text-start">{{ __('promo_admin.codes.view.status') }}</th>
                            <th class="px-3 py-2 text-end">{{ __('promo_admin.codes.view.total') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                        @foreach ($orders as $order)
                            <tr>
                                <td class="px-3 py-2 whitespace-nowrap">
                                    @if ($order['url'])
                                        <a href="{{ $order['url'] }}" class="font-medium text-primary-600 hover:underline dark:text-primary-400">{{ $order['number'] }}</a>
                                    @else
                                        {{ $order['number'] }}
                                    @endif
                                </td>
                                <td class="px-3 py-2 whitespace-nowrap">{{ $order['date'] }}</td>
                                <td class="px-3 py-2">{{ $order['status'] }}</td>
                                <td class="px-3 py-2 whitespace-nowrap text-end">{{ $order['total'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <p class="text-gray-500 dark:text-gray-400">{{ __('promo_admin.codes.view.recent_empty') }}</p>
        @endif
    </div>
</div>
