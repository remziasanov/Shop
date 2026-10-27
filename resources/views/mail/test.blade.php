<x-mail::message>
# {{ __('notifications.mail.test_subject') }}

{{ __('notifications.mail.test_body', ['shop' => $shopName]) }}
</x-mail::message>
