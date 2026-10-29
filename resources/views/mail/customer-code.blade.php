<x-mail::message>
@if (filled($name))
# {!! __('account.mail.greeting', ['name' => \App\Support\MarkdownEscaper::inline($name)]) !!}
@else
# {{ __('account.mail.greeting_anonymous') }}
@endif

{{ __('account.mail.code_intro.'.$purpose->value) }}

<x-mail::panel>
**{{ $code }}**
</x-mail::panel>

{{ __('account.mail.code_valid', ['minutes' => $ttlMinutes]) }}

**{!! \App\Support\MarkdownEscaper::inline($warning) !!}**
@if (filled($footer))

{!! \App\Support\MarkdownEscaper::escape($footer) !!}
@endif
</x-mail::message>
