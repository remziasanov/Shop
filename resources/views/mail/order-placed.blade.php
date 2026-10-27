<x-mail::message>
# {{ __('notifications.mail.greeting', ['name' => $order->customer_name]) }}

{{ $thanks }}

**{{ $order->type->label() }} {{ $order->number }}**

@if ($order->type === \App\Enums\OrderType::Reservation && $order->reserved_until)
**{{ __('notifications.mail.reserved_until', ['date' => $order->reserved_until->format('d.m.Y H:i')]) }}**

@endif
@if ($order->desired_date)
{{ __('notifications.mail.desired_date', ['date' => $order->desired_date->format('d.m.Y')]) }}

@endif
@if ($order->items->isNotEmpty())
## {{ __('notifications.mail.items') }}

@include('mail.partials.items', ['order' => $order])

@endif
@if (filled($order->delivery_method) || filled($order->delivery_address) || filled($order->payment_method))
## {{ __('notifications.mail.delivery') }}

@if (filled($order->delivery_method))
{{ $order->delivery_method }}  
@endif
@if (filled($order->delivery_address))
{{ __('notifications.mail.address') }}: {{ $order->delivery_address }}  
@endif
@if (filled($order->payment_method))
{{ __('notifications.mail.payment') }}: {{ $order->payment_method }}
@endif

@endif
@if (filled($general->phones) || filled($general->email))
{{ __('notifications.mail.contacts') }}: {{ implode(', ', array_filter([...($general->phones ?? []), $general->email])) }}
@endif
</x-mail::message>
