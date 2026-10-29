<?php

declare(strict_types=1);

namespace App\Http\Requests\Account;

use App\Rules\Inn;
use App\Rules\Kpp;
use App\Rules\Ogrn;
use App\Services\Customers\InnValidator;
use Illuminate\Validation\Validator;

/**
 * CA-10: company requisites. INN with the check digits, KPP only for an organization (10-digit INN), OGRN / OGRNIP.
 */
class CompanyRequest extends AccountRequest
{
    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        // Requisites are typed with spaces and dashes; only the digits matter.
        foreach (['inn', 'kpp', 'ogrn'] as $key) {
            if (is_string($this->input($key))) {
                $this->merge([$key => preg_replace('/\s+/', '', (string) $this->input($key))]);
            }
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'inn' => ['required', 'string', new Inn],
            'kpp' => ['nullable', 'string', new Kpp],
            'ogrn' => ['nullable', 'string', new Ogrn],
            'legal_address' => ['required', 'string', 'max:1000'],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $inn = (string) $this->input('inn', '');
                $kpp = (string) $this->input('kpp', '');

                if ($kpp !== '' && ! $validator->errors()->has('inn') && ! app(InnValidator::class)->isLegalEntityInn($inn)) {
                    $validator->errors()->add('kpp', __('requisites.validation.kpp_only_for_legal_entity'));
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        /** @var array<string, string> $attributes */
        $attributes = trans('account.company.fields');

        return $attributes;
    }

    /**
     * @return array{name: string, inn: string, kpp: string|null, ogrn: string|null, legal_address: string}
     */
    public function companyData(): array
    {
        /** @var array{name: string, inn: string, kpp: string|null, ogrn: string|null, legal_address: string} $data */
        $data = $this->safe()->only(['name', 'inn', 'kpp', 'ogrn', 'legal_address']);

        return $data + ['kpp' => null, 'ogrn' => null];
    }
}
