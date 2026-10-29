<?php

declare(strict_types=1);

namespace App\Http\Requests\Account;

use App\Support\PhoneNormalizer;
use Closure;

/**
 * CA-09: an entry of the address book.
 */
class AddressRequest extends AccountRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'label' => ['required', 'string', 'max:100'],
            'recipient_name' => ['required', 'string', 'max:150'],
            'phone' => ['required', 'string', 'max:30', function (string $attribute, mixed $value, Closure $fail): void {
                if (PhoneNormalizer::normalize(is_string($value) ? $value : null) === null) {
                    $fail(__('sales.errors.phone_invalid'));
                }
            }],
            'address' => ['required', 'string', 'max:500'],
            'is_default' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        /** @var array<string, string> $attributes */
        $attributes = trans('account.addresses.fields');

        return $attributes;
    }

    /**
     * @return array{label: string, recipient_name: string, phone: string, address: string, is_default: bool}
     */
    public function addressData(): array
    {
        return [
            'label' => (string) $this->validated('label'),
            'recipient_name' => (string) $this->validated('recipient_name'),
            'phone' => (string) PhoneNormalizer::normalize((string) $this->validated('phone')),
            'address' => (string) $this->validated('address'),
            'is_default' => $this->boolean('is_default'),
        ];
    }
}
