<?php

declare(strict_types=1);

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class LegalSettings extends Settings
{
    /** Seller details (name, INN/OGRN, address). */
    public ?string $seller_details;

    public string $consent_text;

    public bool $cookie_banner_enabled;

    public string $cookie_banner_text;

    /** Page id of the privacy policy (pages arrive in stage 5). */
    public ?int $privacy_page_id;

    /** Page id of the personal data consent. */
    public ?int $consent_page_id;

    /** CX-11: seller bank requisites; with the bank, BIK and account filled the PDF document is an invoice. */
    public ?string $bank_name;

    public ?string $bik;

    /** Settlement account (р/с). */
    public ?string $account;

    /** Correspondent account (к/с). */
    public ?string $corr_account;

    /** CX-11: the VAT line of the document, «Без НДС» by default; empty = no line. */
    public ?string $vat_note;

    /** Footnote of the PDF document. */
    public ?string $invoice_footer;

    /** Private disk path of the signature/stamp image of the PDF document (optional). */
    public ?string $signature_image;

    /**
     * CX-10: bank requisites needed for «Счёт на оплату» are filled (the corr. account is optional).
     */
    public function hasBankRequisites(): bool
    {
        return filled($this->bank_name) && filled($this->bik) && filled($this->account);
    }

    public static function group(): string
    {
        return 'legal';
    }
}
