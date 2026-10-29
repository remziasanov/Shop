<?php

declare(strict_types=1);

namespace App\Http\Requests\Account;

use App\Rules\Inn;
use App\Rules\Kpp;
use App\Services\Customers\InnValidator;
use App\Support\PhoneNormalizer;
use Closure;
use Illuminate\Contracts\Validation\Validator;

/**
 * CG-20: company, INN (check digits, CA-10), KPP (only with a 10-digit INN), contact person, phone, comment and the
 * consent. The e-mail comes from the account, the honeypot and the fill-time token are checked by the controller.
 */
class WholesaleApplicationRequest extends AccountRequest
{
    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        foreach (['inn', 'kpp'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => trim((string) $this->input($field))]);
            }
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'company_name' => ['required', 'string', 'max:255'],
            'inn' => ['required', 'string', new Inn],
            'kpp' => ['nullable', 'string', new Kpp],
            'contact_name' => ['required', 'string', 'max:150'],
            'phone' => ['required', 'string', 'max:30', function (string $attribute, mixed $value, Closure $fail): void {
                if (PhoneNormalizer::normalize(is_string($value) ? $value : null) === null) {
                    $fail(__('sales.errors.phone_invalid'));
                }
            }],
            'comment' => ['nullable', 'string', 'max:2000'],
            'consent' => ['accepted'],
            'website' => ['nullable', 'string'],
            'form_token' => ['nullable', 'string'],
        ];
    }

    /**
     * A KPP belongs to an organization: an entrepreneur (12-digit INN) has none (CA-10).
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $inn = (string) $this->input('inn');
                $kpp = $this->input('kpp');

                if (is_string($kpp) && $kpp !== '' && ! $validator->errors()->has('kpp') && ! app(InnValidator::class)->isLegalEntityInn($inn)) {
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
        $attributes = trans('wholesale.fields');

        return $attributes;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['consent.accepted' => __('sales.errors.consent_required')];
    }

    /**
     * @return array{company_name: string, inn: string, kpp: string|null, contact_name: string, phone: string, comment: string|null}
     */
    public function applicationData(): array
    {
        return [
            'company_name' => trim((string) $this->validated('company_name')),
            'inn' => (string) $this->validated('inn'),
            'kpp' => filled($this->validated('kpp')) ? (string) $this->validated('kpp') : null,
            'contact_name' => trim((string) $this->validated('contact_name')),
            'phone' => (string) PhoneNormalizer::normalize((string) $this->validated('phone')),
            'comment' => filled($this->validated('comment')) ? trim((string) $this->validated('comment')) : null,
        ];
    }
}
