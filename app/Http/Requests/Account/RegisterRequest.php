<?php

declare(strict_types=1);

namespace App\Http\Requests\Account;

use App\Rules\CanonicalEmail;
use App\Rules\CustomerPassword;
use App\Services\Customers\RegistrationData;
use App\Support\PhoneNormalizer;
use Closure;

/**
 * CA-02: name, e-mail, phone (normalized to +7XXXXXXXXXX), password with confirmation, consent. The honeypot and the
 * fill-time token are checked by the controller (FormGuard).
 */
class RegisterRequest extends AccountRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $email = is_string($this->input('email')) ? $this->input('email') : null;

        return [
            'name' => ['required', 'string', 'max:150'],
            'email' => ['bail', 'required', 'string', 'max:190', new CanonicalEmail, 'email:rfc'],
            'phone' => ['required', 'string', 'max:30', function (string $attribute, mixed $value, Closure $fail): void {
                if (PhoneNormalizer::normalize(is_string($value) ? $value : null) === null) {
                    $fail(__('sales.errors.phone_invalid'));
                }
            }],
            'password' => ['required', 'string', 'confirmed', ...CustomerPassword::min(), new CustomerPassword($email)],
            'consent' => ['accepted'],
            'website' => ['nullable', 'string'],
            'form_token' => ['nullable', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        /** @var array<string, string> $attributes */
        $attributes = trans('account.fields');

        return $attributes;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['consent.accepted' => __('sales.errors.consent_required')];
    }

    public function registrationData(string $bindingToken = ''): RegistrationData
    {
        return new RegistrationData(
            name: (string) $this->validated('name'),
            email: $this->email(),
            phone: (string) PhoneNormalizer::normalize((string) $this->validated('phone')),
            password: (string) $this->validated('password'),
            ip: (string) $this->ip(),
            bindingToken: $bindingToken,
        );
    }
}
