@foreach ($orders as $order)
    <tr>
        <td class="nowrap">{{ $order->number }}</td>
        <td class="nowrap">{{ $order->created_at?->format('d.m.Y H:i') }}</td>
        <td>{{ $order->type->label() }}</td>
        <td>{{ $order->status->label($order->type) }}</td>
        <td class="num">{{ $money($order->total) }}</td>
    </tr>
@endforeach
