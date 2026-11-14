<x-mail::message>
# {{ __('telegram.mail.title') }}

{!! __($staff ? 'telegram.mail.text_staff' : 'telegram.mail.text', ['telegram' => \App\Support\MarkdownEscaper::inline($telegram)]) !!}
</x-mail::message>
