<?php

declare(strict_types=1);

namespace App\Livewire\Storefront\Concerns;

use App\Enums\AgeRestriction;
use App\Exceptions\FormGuardException;
use App\Services\Access\ViewerContext;
use App\Services\Content\MenuService;
use App\Services\Sales\FormGuard;
use App\Settings\LegalSettings;
use App\Support\PhoneNormalizer;
use Closure;
use Livewire\Attributes\Locked;

/**
 * Shared parts of the checkout, reservation and inquiry forms: spam protection (SF-48, D-048), contact field
 * rules (SF-44, SF-71) and the consent link. Server-side rules are authoritative; the Alpine phone mask is cosmetic.
 */
trait SalesFormSupport
{
    /** Signed render timestamp issued with the form (FormGuard). */
    #[Locked]
    public string $formToken = '';

    /** Honeypot: real visitors never see or fill it. */
    public string $website = '';

    public function mountSalesFormSupport(): void
    {
        $this->formToken = app(FormGuard::class)->issueToken();

        // 17.7: a signed-in customer gets name, phone and e-mail filled in (they can still be changed).
        $customer = app(ViewerContext::class)->customer();

        if ($customer !== null) {
            $this->name = $customer->name;
            $this->phone = (string) $customer->phone;
            $this->email = $customer->email;
        }
    }

    /**
     * CA-01, CG-12: the viewer has to sign in before ordering, reserving or inquiring; the form shows a sign-in
     * link instead. The server enforces the same rule in OrderService.
     */
    protected function mustSignIn(): bool
    {
        return ! app(ViewerContext::class)->mayOrder();
    }

    /**
     * Runs the honeypot / too-fast / rate-limit checks; a failure is shown as the form error.
     */
    protected function passesGuard(): bool
    {
        try {
            app(FormGuard::class)->check($this->website, $this->formToken, (string) request()->ip());
        } catch (FormGuardException $e) {
            $this->addError('form', $e->getMessage());

            return false;
        }

        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function contactRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'phone' => ['required', 'string', 'max:30', $this->phoneRule()],
            'email' => ['nullable', 'email', 'max:190'],
            'comment' => ['nullable', 'string', 'max:2000'],
            'consent' => ['accepted'],
        ];
    }

    /**
     * AG-11: the age to confirm (null = no checkbox). Forms with restricted products override it.
     */
    protected function ageLevel(): ?AgeRestriction
    {
        return null;
    }

    /**
     * AG-11: the age checkbox is required exactly when the form's products are restricted (the using component
     * provides ageLevel()). The server re-checks it in OrderService, independent of the form.
     *
     * @return array<string, array<int, mixed>>
     */
    protected function ageRules(): array
    {
        return $this->ageLevel() !== null ? ['ageConfirmed' => ['accepted']] : [];
    }

    /**
     * @return array<string, string>
     */
    protected function ageMessages(): array
    {
        $level = $this->ageLevel();

        return $level !== null ? ['ageConfirmed.accepted' => __('age_gate.errors.confirm_required', ['age' => $level->minAge()])] : [];
    }

    protected function phoneRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (PhoneNormalizer::normalize(is_string($value) ? $value : null) === null) {
                $fail(__('sales.errors.phone_invalid'));
            }
        };
    }

    /**
     * @return array<string, string>
     */
    protected function contactMessages(): array
    {
        return [
            'name.required' => __('sales.errors.name_required'),
            'consent.accepted' => __('sales.errors.consent_required'),
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function contactAttributes(): array
    {
        return [
            'name' => __('storefront_sales.fields.name'),
            'phone' => __('storefront_sales.fields.phone'),
            'email' => __('storefront_sales.fields.email'),
            'comment' => __('storefront_sales.fields.comment'),
            'consent' => __('storefront_sales.fields.consent'),
            'qty' => __('storefront_sales.fields.qty'),
        ];
    }

    /**
     * Consent label and link for the form view (SF-71): the consent page when one is chosen and active,
     * otherwise no link (the text stays).
     *
     * @return array{text: string, url: string|null}
     */
    protected function consentLink(): array
    {
        return [
            'text' => app(LegalSettings::class)->consent_text,
            'url' => app(MenuService::class)->consentLink()['url'] ?? null,
        ];
    }

    protected function clientIp(): string
    {
        return (string) request()->ip();
    }

    protected function clientUserAgent(): ?string
    {
        $agent = request()->userAgent();

        return $agent !== null ? mb_substr($agent, 0, 500) : null;
    }
}
