@php
    use App\Enums\OrderType;
@endphp
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    @include('pdf.partials.style')
</head>
<body>
@include('pdf.partials.header')

<h1>{{ $title }}</h1>

@if ($invoice)
    {{-- CX-11: bank requisites of the seller, only for an invoice. --}}
    <table class="grid">
        <tr>
            <td colspan="2">{{ __('pdf.document.bank') }}: <strong>{{ $legal->bank_name }}</strong></td>
            <td style="width: 22%;">{{ __('pdf.document.account') }}</td>
            <td style="width: 28%;">{{ $legal->account }}</td>
        </tr>
        <tr>
            <td style="width: 15%;">{{ __('pdf.document.bik') }}</td>
            <td>{{ $legal->bik }}</td>
            <td>{{ __('pdf.document.corr_account') }}</td>
            <td>{{ $legal->corr_account }}</td>
        </tr>
    </table>
@endif

<table class="plain" style="margin-top: 8pt;">
    <tr>
        <td class="muted" style="width: 16%;">{{ __('pdf.document.seller') }}</td>
        <td>
            <strong>{{ $general->shop_name }}</strong>
            @if ($legal->seller_details)
                <br>{!! nl2br(e($legal->seller_details)) !!}
            @endif
        </td>
    </tr>
    <tr>
        <td class="muted">{{ __('pdf.document.payer') }}</td>
        <td>
            @if ($company)
                <strong>{{ $company->name }}</strong><br>
                {{ __('pdf.document.inn') }} {{ $company->inn }}@if ($company->kpp), {{ __('pdf.document.kpp') }} {{ $company->kpp }}@endif @if ($company->ogrn), {{ __('pdf.document.ogrn') }} {{ $company->ogrn }}@endif<br>
                {{ $company->legal_address }}
            @else
                <strong>{{ $order->customer_name }}</strong>@if ($order->customer_phone), {{ $order->customer_phone }}@endif
            @endif
        </td>
    </tr>
    @if ($order->delivery_method)
        <tr>
            <td class="muted">{{ __('pdf.document.delivery') }}</td>
            <td>{{ $order->delivery_method }}@if ($order->delivery_address), {{ $order->delivery_address }}@endif</td>
        </tr>
    @endif
    @if ($order->payment_method)
        <tr>
            <td class="muted">{{ __('pdf.document.payment') }}</td>
            <td>{{ $order->payment_method }}</td>
        </tr>
    @endif
</table>

<table class="grid" style="margin-top: 10pt;">
    <thead>
    <tr>
        <th class="num" style="width: 5%;">{{ __('pdf.items.no') }}</th>
        <th style="width: 14%;">{{ __('pdf.items.sku') }}</th>
        <th>{{ __('pdf.items.name') }}</th>
        <th class="num">{{ __('pdf.items.qty') }}</th>
        <th class="num">{{ __('pdf.items.price') }}</th>
        <th class="num">{{ __('pdf.items.sum') }}</th>
    </tr>
    </thead>
    <tbody>
    @foreach ($order->items as $item)
        <tr>
            <td class="num">{{ $loop->iteration }}</td>
            <td>{{ $item->sku }}</td>
            <td>{{ $item->displayName() }}</td>
            <td class="num">{{ $item->qty }} {{ $item->unit }}</td>
            <td class="num">{{ $money($item->price) }}</td>
            <td class="num">{{ $money($item->total) }}</td>
        </tr>
    @endforeach
    </tbody>
</table>

<table class="totals">
    <tr><td>{{ __('pdf.document.items_total') }}</td><td class="right nowrap">{{ $money($order->items_total) }}</td></tr>
    @if ($order->type === OrderType::Order)
        <tr><td>{{ __('pdf.document.delivery_price') }}</td><td class="right nowrap">{{ $money($order->delivery_price) }}</td></tr>
    @endif
    @foreach ($discountLines as $line)
        <tr><td>{{ $line['label'] }}</td><td class="right nowrap">{{ $line['value'] }}</td></tr>
    @endforeach
    <tr class="grand"><td>{{ __('pdf.document.total') }}</td><td class="right nowrap">{{ $money($order->total) }}</td></tr>
</table>

@if ($totalInWords !== null)
    <p class="words">{{ __('pdf.document.total_in_words') }}: {{ $totalInWords }}</p>
@endif
@if (filled($legal->vat_note))
    <p>{{ $legal->vat_note }}</p>
@endif

<table class="signs">
    <tr>
        <td>
            @if ($signature)
                <img class="stamp" src="{{ $signature }}" alt="">
            @endif
            <div class="sign-line">{{ __('pdf.document.signature_seller') }}</div>
        </td>
        <td><div class="sign-line">{{ __('pdf.document.signature_customer') }}</div></td>
    </tr>
</table>

@if (filled($legal->invoice_footer))
    <p class="footer">{!! nl2br(e($legal->invoice_footer)) !!}</p>
@endif
</body>
</html>
