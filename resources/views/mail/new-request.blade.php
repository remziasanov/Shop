<x-mail::message>
# {{ __('notifications.mail.new_subject', ['type' => mb_strtolower($order->type->label()), 'number' => $order->number]) }}

{{ __('notifications.mail.customer') }}: {{ $order->customer_name }}  
{{ __('notifications.mail.phone') }}: {{ $order->customer_phone }}  
@if (filled($order->customer_email))
{{ __('notifications.mail.email') }}: {{ $order->customer_email }}  
@endif
@if ($order->type === \App\Enums\OrderType::Reservation && $order->reserved_until)
{{ __('notifications.mail.reserved_until', ['date' => $order->reserved_until->format('d.m.Y H:i')]) }}  
@endif
@if (filled($order->delivery_method))
{{ __('notifications.mail.delivery') }}: {{ $order->delivery_method }}@if (filled($order->delivery_address)), {{ $order->delivery_address }}@endif  
@endif
@if (filled($order->customer_comment))
{{ __('notifications.mail.comment') }}: {{ $order->customer_comment }}
@endif

@if ($order->items->isNotEmpty())
@include('mail.partials.items', ['order' => $order])
@endif

<x-mail::button :url="$adminUrl">
{{ __('notifications.mail.open') }}
</x-mail::button>
</x-mail::message>
