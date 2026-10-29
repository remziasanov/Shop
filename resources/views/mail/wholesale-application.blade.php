<x-mail::message>
# {{ __('wholesale.mail.shop_subject') }}

{{ __('wholesale.mail.company') }}: @mdi($application->company_name)
{{ __('wholesale.mail.inn') }}: @mdi($application->inn)
@if (filled($application->kpp))
{{ __('wholesale.mail.kpp') }}: @mdi($application->kpp)
@endif
{{ __('wholesale.mail.contact') }}: @mdi($application->contact_name)
{{ __('wholesale.mail.phone') }}: @mdi($application->phone)
{{ __('wholesale.mail.email') }}: @mdi($application->email)
@if (filled($application->comment))
{{ __('wholesale.mail.comment') }}: @md($application->comment)
@endif
@if ($warnings !== [])

@foreach ($warnings as $warning)
**{{ __('wholesale.mail.warning') }}:** @mdi($warning)
@endforeach
@endif

<x-mail::button :url="$adminUrl">
{{ __('wholesale.mail.open') }}
</x-mail::button>
</x-mail::message>
