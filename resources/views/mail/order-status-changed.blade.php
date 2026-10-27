<x-mail::message>
# {{ __('notifications.mail.greeting', ['name' => $order->customer_name]) }}

{{ __('notifications.mail.status_text', ['number' => $order->number, 'status' => $statusLabel]) }}

@if ($order->type === \App\Enums\OrderType::Reservation && $order->reserved_until && $order->status === \App\Enums\OrderStatus::Reserved)
**{{ __('notifications.mail.reserved_until', ['date' => $order->reserved_until->format('d.m.Y H:i')]) }}**

@endif
@if ($order->items->isNotEmpty())
@include('mail.partials.items', ['order' => $order])
@endif
</x-mail::message>
