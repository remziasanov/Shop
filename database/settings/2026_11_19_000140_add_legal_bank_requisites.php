<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * CX-10, CX-11 (TZ 18.4, 18.5): bank requisites of the seller for the invoice (all empty = the document is a «Бланк
 * заказа»), the VAT note, the footnote and an optional signature/stamp image (private disk path).
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('legal.bank_name', null);
        $this->migrator->add('legal.bik', null);
        $this->migrator->add('legal.account', null);
        $this->migrator->add('legal.corr_account', null);
        $this->migrator->add('legal.vat_note', 'Без НДС');
        $this->migrator->add('legal.invoice_footer', 'Документ не является первичным бухгалтерским документом. Проверьте у бухгалтера.');
        $this->migrator->add('legal.signature_image', null);
    }

    public function down(): void
    {
        foreach (['bank_name', 'bik', 'account', 'corr_account', 'vat_note', 'invoice_footer', 'signature_image'] as $key) {
            $this->migrator->delete('legal.'.$key);
        }
    }
};
