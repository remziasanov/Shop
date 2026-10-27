@php($money = fn (?string $value): string => $value === null ? __('notifications.mail.price_on_request') : \App\Support\Money::format(\App\Support\Money::fromRubles($value)))
<x-mail::table>
| {{ __('notifications.mail.item_name') }} | {{ __('notifications.mail.item_qty') }} | {{ __('notifications.mail.item_total') }} |
| :-- | :-: | --: |
@foreach ($order->items as $item)
| {{ str_replace('|', '/', $item->name) }} | {{ $item->qty }} | {{ $money($item->total) }} |
@endforeach
</x-mail::table>

@if ($order->total !== null)
@if ((float) $order->delivery_price > 0)
{{ __('notifications.mail.items_total') }}: {{ $money($order->items_total) }}  
{{ __('notifications.mail.delivery_price') }}: {{ $money($order->delivery_price) }}  
@endif
**{{ __('notifications.mail.total') }}: {{ $money($order->total) }}**
@endif
