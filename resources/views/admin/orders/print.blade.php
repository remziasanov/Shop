@php
    use App\Enums\OrderType;
    use App\Support\Money;

    $money = fn (?string $value, string $empty = '—'): string => $value === null ? $empty : Money::format(Money::fromRubles($value));
    $phones = array_filter((array) $general->phones);
@endphp
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ __('admin_orders.print.title', ['number' => $order->number]) }}</title>
    <style>
        :root { color-scheme: light; }
        * { box-sizing: border-box; }
        body { margin: 0; padding: 24px 16px; font: 14px/1.45 Arial, Helvetica, sans-serif; color: #111; background: #fff; }
        .sheet { max-width: 800px; margin: 0 auto; }
        h1 { font-size: 22px; margin: 0 0 4px; }
        h2 { font-size: 15px; margin: 22px 0 6px; }
        .muted { color: #555; }
        .head { display: flex; justify-content: space-between; gap: 16px; flex-wrap: wrap; border-bottom: 2px solid #111; padding-bottom: 12px; }
        table { width: 100%; border-collapse: collapse; margin-top: 6px; }
        th, td { border: 1px solid #999; padding: 5px 7px; text-align: left; vertical-align: top; }
        th { background: #f1f1f1; font-weight: 600; }
        td.num, th.num { text-align: right; white-space: nowrap; }
        dl { margin: 0; display: grid; grid-template-columns: max-content 1fr; gap: 3px 14px; }
        dt { color: #555; }
        dd { margin: 0; }
        .totals { margin-top: 10px; margin-left: auto; width: 100%; max-width: 320px; }
        .totals div { display: flex; justify-content: space-between; padding: 2px 0; }
        .totals .grand { font-weight: 700; font-size: 16px; border-top: 1px solid #111; margin-top: 4px; padding-top: 6px; }
        .signs { display: flex; gap: 40px; margin-top: 40px; }
        .signs div { flex: 1; border-top: 1px solid #111; padding-top: 4px; font-size: 12px; color: #555; }
        .toolbar { margin-bottom: 16px; }
        button { font: inherit; padding: 8px 18px; cursor: pointer; }
        @media print { .toolbar { display: none; } body { padding: 0; } }
    </style>
</head>
<body>
<div class="sheet">
    <div class="toolbar">
        <button type="button" onclick="window.print()">{{ __('admin_orders.print.button') }}</button>
    </div>

    <div class="head">
        <div>
            <h1>{{ $order->type->label() }} {{ $order->number }}</h1>
            <div class="muted">{{ __('admin_orders.print.created_at') }}: {{ $order->created_at?->format('d.m.Y H:i') }}</div>
            @if ($order->type === OrderType::Reservation && $order->reserved_until)
                <div class="muted">{{ __('admin_orders.print.reserved_until') }}: {{ $order->reserved_until->format('d.m.Y H:i') }}</div>
            @endif
        </div>
        <div>
            <strong>{{ $general->shop_name }}</strong>
            @if ($legal->seller_details)
                <div class="muted">{!! nl2br(e($legal->seller_details)) !!}</div>
            @endif
            @if ($general->address)
                <div class="muted">{{ $general->address }}</div>
            @endif
            @if ($phones !== [])
                <div class="muted">{{ __('admin_orders.print.phones') }}: {{ implode(', ', $phones) }}</div>
            @endif
            @if ($general->email)
                <div class="muted">{{ $general->email }}</div>
            @endif
        </div>
    </div>

    <h2>{{ __('admin_orders.print.customer') }}</h2>
    <dl>
        <dt>{{ __('admin_orders.view.name') }}</dt><dd>{{ $order->customer_name }}</dd>
        <dt>{{ __('admin_orders.view.phone') }}</dt><dd>{{ $order->customer_phone }}</dd>
        @if ($order->customer_email)
            <dt>{{ __('admin_orders.view.email') }}</dt><dd>{{ $order->customer_email }}</dd>
        @endif
        @if ($order->delivery_method)
            <dt>{{ __('admin_orders.print.delivery') }}</dt><dd>{{ $order->delivery_method }}@if ($order->delivery_address), {{ $order->delivery_address }}@endif</dd>
        @endif
        @if ($order->payment_method)
            <dt>{{ __('admin_orders.print.payment') }}</dt><dd>{{ $order->payment_method }}</dd>
        @endif
    </dl>

    <h2>{{ __('admin_orders.print.items') }}</h2>
    <table>
        <thead>
        <tr>
            <th class="num">{{ __('admin_orders.print.no') }}</th>
            <th>{{ __('admin_orders.print.sku') }}</th>
            <th>{{ __('admin_orders.print.name') }}</th>
            <th class="num">{{ __('admin_orders.print.qty') }}</th>
            <th class="num">{{ __('admin_orders.print.price') }}</th>
            <th class="num">{{ __('admin_orders.print.sum') }}</th>
        </tr>
        </thead>
        <tbody>
        @foreach ($order->items as $item)
            <tr>
                <td class="num">{{ $loop->iteration }}</td>
                <td>{{ $item->sku }}</td>
                <td>{{ $item->name }}</td>
                <td class="num">{{ $item->qty }} {{ $item->unit }}</td>
                <td class="num">{{ $money($item->price, __('admin_orders.view.price_on_request')) }}</td>
                <td class="num">{{ $money($item->total) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <div class="totals">
        <div><span>{{ __('admin_orders.print.items_total') }}</span><span>{{ $money($order->items_total) }}</span></div>
        @if ($order->type === OrderType::Order)
            <div><span>{{ __('admin_orders.view.delivery_price') }}</span><span>{{ $money($order->delivery_price) }}</span></div>
        @endif
        <div class="grand"><span>{{ __('admin_orders.print.total') }}</span><span>{{ $money($order->total) }}</span></div>
    </div>

    @if ($order->customer_comment)
        <h2>{{ __('admin_orders.print.comment') }}</h2>
        <div>{!! nl2br(e($order->customer_comment)) !!}</div>
    @endif

    <div class="signs">
        <div>{{ __('admin_orders.print.signature_customer') }}</div>
        <div>{{ __('admin_orders.print.signature_seller') }}</div>
    </div>
</div>
</body>
</html>
