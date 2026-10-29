<?php

declare(strict_types=1);

namespace App\Http\Requests\Account;

/**
 * Entry of a 6-digit e-mail code (the address comes from the session of the flow).
 */
class CodeRequest extends AccountRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'regex:/^\s*\d{6}\s*$/'],
            'remember' => ['nullable', 'boolean'],
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
        return ['code.regex' => __('account.errors.code_format')];
    }

    public function code(): string
    {
        return trim((string) $this->validated('code'));
    }
}
