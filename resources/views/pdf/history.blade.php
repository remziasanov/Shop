<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <title>{{ __('pdf.history.title') }}</title>
    @include('pdf.partials.style')
</head>
<body>
@include('pdf.partials.header')

<h1>{{ __('pdf.history.title') }}</h1>
<table class="plain">
    <tr><td class="muted" style="width: 18%;">{{ __('pdf.history.customer') }}</td><td>{{ $customerLine }}</td></tr>
    <tr><td class="muted">{{ __('pdf.history.period') }}</td><td>{{ $period }}</td></tr>
    @if ($filters !== '')
        <tr><td class="muted">{{ __('pdf.history.filters') }}</td><td>{{ $filters }}</td></tr>
    @endif
    <tr><td class="muted">{{ __('pdf.history.created') }}</td><td>{{ $generatedAt }}</td></tr>
</table>

<div style="margin-top: 8pt;">
    {!! $body !!}
</div>

<table class="totals">
    <tr class="grand">
        <td>{{ __('pdf.history.period_total', ['count' => $count]) }}</td>
        <td class="right nowrap">{{ $periodTotal }}</td>
    </tr>
</table>
</body>
</html>
