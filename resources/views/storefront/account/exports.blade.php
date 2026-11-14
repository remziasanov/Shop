@extends('storefront.layouts.app')

@section('content')
    <div class="mx-auto w-full max-w-4xl py-6">
        <p class="text-sm"><a href="{{ route('customer.orders') }}" class="inline-flex min-h-11 items-center text-primary underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary">{{ __('customer_export.list.back') }}</a></p>
        <h1 class="mt-2 text-2xl font-bold md:text-3xl">{{ __('customer_export.list.title') }}</h1>
        <p class="mt-1 text-sm text-ink-mid">{{ __('customer_export.list.lead', ['count' => 20, 'hours' => $hours]) }}</p>
        <div class="mt-4">
            <x-account.errors />
        </div>

        {{-- CX-08: the last 20 exports; a «Скачать» button only while the file can be downloaded. --}}
        @if ($exports->isEmpty())
            <p class="mt-6 rounded-card border border-line p-4 text-ink-mid" data-exports-empty>{{ __('customer_export.list.empty') }}</p>
        @else
            <ul class="mt-6 space-y-3 md:space-y-0 md:divide-y md:divide-line md:rounded-card md:border md:border-line">
                @foreach ($exports as $export)
                    @php
                        $params = (array) $export->params;
                        $gone = $service->isGone($export);
                    @endphp
                    <li class="flex flex-wrap items-center justify-between gap-3 p-4 max-md:rounded-card max-md:border max-md:border-line" data-export>
                        <div>
                            <p class="font-semibold">
                                {{ __('customer_export.list.period_value', ['from' => \Carbon\CarbonImmutable::parse($params['from'] ?? $export->created_at)->format('d.m.Y'), 'until' => \Carbon\CarbonImmutable::parse($params['until'] ?? $export->created_at)->format('d.m.Y')]) }}
                            </p>
                            <p class="text-sm text-ink-mid">
                                {{ $export->type->extension() === 'pdf' ? 'PDF' : 'Excel' }}@if (! empty($params['with_items'])), {{ __('customer_export.list.with_items') }}@endif
                                · {{ $gone ? __('customer_export.list.gone') : $export->status->label() }}
                                @if ($export->file_size !== null && ! $gone)· {{ \App\Support\FileSize::format($export->file_size) }}@endif
                            </p>
                            @if ($export->status === \App\Enums\ExportStatus::Failed && $export->failure_message)
                                <p class="text-sm text-danger">{{ $export->failure_message }}</p>
                            @endif
                            @if ($export->expires_at && $export->status === \App\Enums\ExportStatus::Completed && ! $gone)
                                <p class="text-sm text-ink-mid">{{ __('customer_export.list.expires') }}: {{ $export->expires_at->format('d.m.Y H:i') }}</p>
                            @endif
                        </div>
                        @if ($service->isDownloadable($export))
                            <a href="{{ route('customer.exports.download', $export->uuid) }}" class="flex min-h-11 items-center justify-center rounded-btn bg-primary px-4 py-2 text-sm font-medium text-on-primary hover:opacity-90 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary max-md:w-full" data-export-download>{{ __('customer_export.list.download') }}</a>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
@endsection
