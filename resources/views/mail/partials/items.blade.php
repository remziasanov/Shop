@php($money = fn (?string $value): string => $value === null ? __('notifications.mail.price_on_request') : \App\Support\Money::format(\App\Support\Money::fromRubles($value)))
<x-mail::table>
| {{ __('notifications.mail.item_name') }} | {{ __('notifications.mail.item_qty') }} | {{ __('notifications.mail.item_total') }} |
| :-- | :-: | --: |
@foreach ($order->items as $item)
| @mdi($item->displayName()) | {{ $item->qty }} | {{ $money($item->total) }} |
@endforeach
</x-mail::table>

@if ($order->total !== null)
@php($discountLines = \App\Support\OrderDiscountLines::for($order))
@if ((float) $order->delivery_price > 0 || $discountLines !== [])
{{ __('notifications.mail.items_total') }}: {{ $money($order->items_total) }}  
@foreach ($discountLines as $discountLine)
{{ $discountLine['label'] }}: {{ $discountLine['value'] }}  
@endforeach
@if ((float) $order->delivery_price > 0)
{{ __('notifications.mail.delivery_price') }}: {{ $money($order->delivery_price) }}  
@endif
@endif
**{{ __('notifications.mail.total') }}: {{ $money($order->total) }}**
@endif
