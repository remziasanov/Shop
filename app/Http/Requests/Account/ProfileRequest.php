<?php

declare(strict_types=1);

namespace App\Http\Requests\Account;

use App\Services\PhoneVerification\PhoneVerificationService;
use Closure;

/**
 * CA-08: name and phone of the profile.
 */
class ProfileRequest extends AccountRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'phone' => ['required', 'string', 'max:30', function (string $attribute, mixed $value, Closure $fail): void {
                if (app(PhoneVerificationService::class)->contactPhone(is_string($value) ? $value : null) === null) {
                    $fail(__('sales.errors.phone_invalid'));
                }
            }],
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

    public function normalizedPhone(): string
    {
        return (string) app(PhoneVerificationService::class)->contactPhone((string) $this->validated('phone'));
    }
}
