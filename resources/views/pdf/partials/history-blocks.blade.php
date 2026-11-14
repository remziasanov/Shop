@foreach ($orders as $order)
    <div class="order">
        <table class="order-head">
            <tr>
                <td>{{ $order->type->label() }} {{ $order->number }}</td>
                <td>{{ $order->created_at?->format('d.m.Y H:i') }}</td>
                <td>{{ $order->status->label($order->type) }}</td>
                <td class="right nowrap">{{ $money($order->total) }}</td>
            </tr>
        </table>
        @if ($order->items->isNotEmpty())
            <table class="grid" style="margin-top: 0;">
                <thead>
                <tr>
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
                        <td>{{ $item->sku }}</td>
                        <td>{{ $item->displayName() }}</td>
                        <td class="num">{{ $item->qty }} {{ $item->unit }}</td>
                        <td class="num">{{ $money($item->price) }}</td>
                        <td class="num">{{ $money($item->total) }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </div>
@endforeach
