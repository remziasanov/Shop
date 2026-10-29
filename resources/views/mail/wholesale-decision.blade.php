<x-mail::message>
# {!! __('account.mail.greeting', ['name' => \App\Support\MarkdownEscaper::inline($name)]) !!}

@if ($approved)
{!! __('wholesale.mail.approved_text', ['group' => \App\Support\MarkdownEscaper::inline($groupName)]) !!}

{{ __('wholesale.mail.available_title') }}

@foreach ($available as $line)
- @mdi($line)
@endforeach

<x-mail::button :url="$url">
{{ __('wholesale.mail.approved_button') }}
</x-mail::button>
@else
{{ __('wholesale.mail.rejected_text') }}

**{{ __('wholesale.mail.reason') }}:** @md($reason)

{{ __('wholesale.mail.reapply') }}

<x-mail::button :url="$url">
{{ __('wholesale.mail.rejected_button') }}
</x-mail::button>
@endif
</x-mail::message>
