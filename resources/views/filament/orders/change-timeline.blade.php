@php
    $timeline = app(\App\Services\Orders\OrderTimeline::class)->for($getRecord());
@endphp

<div class="space-y-4 text-sm">
    @if ($timeline === [])
        <p class="text-gray-500 dark:text-gray-400">{{ __('order_edit.timeline.empty') }}</p>
    @endif

    @foreach ($timeline as $entry)
        <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <span class="font-medium text-gray-950 dark:text-white">{{ $entry['text'] }}</span>
                <span class="text-gray-500 dark:text-gray-400">{{ $entry['at']->format('d.m.Y H:i') }} · {{ $entry['user'] }}</span>
            </div>

            @if ($entry['comment'])
                <p class="mt-1 text-gray-500 dark:text-gray-400">{{ $entry['comment'] }}</p>
            @endif

            @if ($entry['rows'] !== [])
                <table class="mt-2 w-full text-start">
                    <thead>
                        <tr class="text-gray-500 dark:text-gray-400">
                            <th class="py-1 pe-3 text-start font-normal">{{ __('order_edit.timeline.event') }}</th>
                            <th class="py-1 pe-3 text-start font-normal">{{ __('order_edit.timeline.was') }}</th>
                            <th class="py-1 text-start font-normal">{{ __('order_edit.timeline.now') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($entry['rows'] as $row)
                            <tr>
                                <td class="py-1 pe-3">{{ $row['title'] }}</td>
                                <td class="py-1 pe-3">{{ $row['old'] ?? '—' }}</td>
                                <td class="py-1 font-medium">{{ $row['new'] ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    @endforeach
</div>
