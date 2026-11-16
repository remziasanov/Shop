<x-mail::message>
# {{ __('account.mail.email_changed_title') }}

{!! __('account.mail.email_changed_text', ['email' => \App\Support\MarkdownEscaper::inline($newEmail)]) !!}

**{{ __('account.mail.email_changed_hint') }}**
</x-mail::message>
