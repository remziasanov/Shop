<?php

declare(strict_types=1);

namespace App\Http\Requests\Account;

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
        return ['email' => ['required', 'string', 'email:rfc', 'max:190']];
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
