@php
    $report = $report ?? [];
    $toCreate = $report['to_create'] ?? [];
@endphp
<div class="space-y-4 text-sm" data-testid="attribute-migration-preview">
    @if ($progress)
        <p class="font-medium text-warning-600 dark:text-warning-400">
            {{ __('admin_attributes.migration.in_progress', ['done' => $progress['done'], 'total' => $progress['total']]) }}
        </p>
    @endif

    <ul class="list-disc space-y-1 pl-5">
        <li>{{ __('admin_attributes.migration.products', ['count' => $report['products'] ?? 0]) }}</li>
        <li>{{ __('admin_attributes.migration.to_create', ['count' => count($toCreate)]) }}</li>
        <li>{{ __('admin_attributes.migration.existing', ['count' => count($report['existing'] ?? [])]) }}</li>
        <li>{{ __('admin_attributes.migration.values', ['count' => $report['values_to_create'] ?? 0]) }}</li>
    </ul>

    @if (! empty($report['merged_names']))
        <div>
            <p class="font-medium">{{ __('admin_attributes.migration.merged') }}</p>
            <ul class="list-disc pl-5">
                @foreach ($report['merged_names'] as $names)
                    <li>{{ implode(' = ', $names) }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if (! empty($report['disputed']))
        <div>
            <p class="font-medium">{{ __('admin_attributes.migration.disputed') }}</p>
            <ul class="list-disc pl-5">
                @foreach ($report['disputed'] as $item)
                    <li>{{ __('admin_attributes.migration.disputed_item', ['name' => $item['name'], 'type' => __('attributes.types.'.$item['type']), 'share' => $item['numeric_share']]) }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if (! empty($report['non_filterable']))
        <div>
            <p class="font-medium">{{ __('admin_attributes.migration.non_filterable') }}</p>
            <ul class="list-disc pl-5">
                @foreach ($report['non_filterable'] as $item)
                    <li>{{ $item['name'] }} ({{ $item['reason'] === 'long' ? __('admin_attributes.migration.reason_long') : __('admin_attributes.migration.reason_distinct') }})</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($toCreate !== [])
        <div>
            <p class="font-medium">{{ __('admin_attributes.migration.new_attributes') }}</p>
            <ul class="list-disc pl-5">
                @foreach (array_slice($toCreate, 0, 50) as $item)
                    <li>{{ $item['name'] }}{{ $item['unit'] ? ', '.$item['unit'] : '' }} — {{ __('attributes.types.'.$item['type']) }}@if ($item['type'] === 'list'), {{ __('admin_attributes.migration.new_values', ['count' => $item['new_values']]) }}@endif</li>
                @endforeach
            </ul>
        </div>
    @endif

    <p class="text-gray-600 dark:text-gray-400">{{ __('admin_attributes.migration.hint') }}</p>
</div>
