<?php

declare(strict_types=1);

namespace App\Rules;

use App\Settings\CustomerSettings;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Customer password policy (CA-02, CA-23, 17.8): at least `password_min_length` characters (8 by default) and not equal
 * to the customer's e-mail address (case-insensitive).
 */
final class CustomerPassword implements ValidationRule
{
    public function __construct(private readonly ?string $email = null) {}

    /**
     * @return list<string>
     */
    public static function min(): array
    {
        return ['min:'.max(1, app(CustomerSettings::class)->password_min_length)];
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        if ($this->email !== null && $this->email !== '' && mb_strtolower($value) === mb_strtolower($this->email)) {
            $fail(__('account.errors.password_equals_email'));
        }
    }
}
