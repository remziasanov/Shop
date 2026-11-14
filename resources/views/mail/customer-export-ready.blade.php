<x-mail::message>
# {!! __('account.mail.greeting', ['name' => \App\Support\MarkdownEscaper::inline($name)]) !!}

{{ __('customer_export.mail.text', ['format' => $format, 'until' => $until]) }}

<x-mail::button :url="$url">
{{ __('customer_export.mail.button') }}
</x-mail::button>

{{ __('customer_export.mail.login_hint') }}
</x-mail::message>
