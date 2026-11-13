<x-mail::message>
# {!! __('notifications.mail.greeting', ['name' => \App\Support\MarkdownEscaper::inline($order->customer_name)]) !!}

{{ __('order_edit.mail.text', ['number' => $order->number]) }}

@if ($order->items->isNotEmpty())
@include('mail.partials.items', ['order' => $order])
@endif

{{ __('order_edit.mail.was') }}: {{ $wasTotal }}  
@if ($delta !== null)
{{ __('order_edit.mail.delta') }}: {{ $delta }}  
@endif
**{{ __('order_edit.mail.now') }}: {{ $nowTotal }}**
</x-mail::message>
