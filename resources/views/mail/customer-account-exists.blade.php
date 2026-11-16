<x-mail::message>
# {{ __('account.mail.exists_title') }}

{{ __('account.mail.exists_text') }}

<x-mail::button :url="$loginUrl">
{{ __('account.mail.exists_login') }}
</x-mail::button>

[{{ __('account.mail.exists_reset') }}]({{ $resetUrl }})
</x-mail::message>
