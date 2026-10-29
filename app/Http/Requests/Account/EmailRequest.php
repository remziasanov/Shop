<?php

declare(strict_types=1);

namespace App\Http\Requests\Account;

use App\Rules\CanonicalEmail;

/**
 * A form with an e-mail address only: «Войти по коду», «Забыли пароль», the new address of an e-mail change.
 */
class EmailRequest extends AccountRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return ['email' => ['bail', 'required', 'string', 'max:190', new CanonicalEmail, 'email:rfc']];
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
}
