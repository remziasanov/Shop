<x-mail::message>
# {!! __('notifications.mail.greeting', ['name' => \App\Support\MarkdownEscaper::inline($order->customer_name)]) !!}

{{ $thanks }}

@foreach ($requests as $request)
@include('mail.partials.placed-request', ['order' => $request])

@endforeach
@if (filled($telegramLink ?? null))
[{{ __('telegram.order_link.button') }}]({{ $telegramLink }})

@endif
@if (filled($general->phones) || filled($general->email))
{{ __('notifications.mail.contacts') }}: {!! implode(', ', array_map(\App\Support\MarkdownEscaper::inline(...), array_filter([...($general->phones ?? []), $general->email]))) !!}
@endif
</x-mail::message>
