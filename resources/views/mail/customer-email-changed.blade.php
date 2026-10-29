<x-mail::message>
# {{ __('account.mail.email_changed_title') }}

{!! __('account.mail.email_changed_text', ['email' => \App\Support\MarkdownEscaper::inline($newEmail)]) !!}
</x-mail::message>
