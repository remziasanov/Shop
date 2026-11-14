<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * CX-04, CX-05, CX-07, CX-09 (TZ 18.4): thresholds of the synchronous generation, hard size limits, retention of the
 * file, rate limits and the e-mail with the link. Defaults as in the TZ, except the PDF hard limits: the measurement on
 * the acceptance run (TZ 18.6) showed that dompdf needs about 0.15 MB per table row, so 1 000 requests (about 170 MB)
 * do not fit the 128 MB of section 2, which wins over 18.4. The PDF limits are 400 requests without and 100 with items,
 * and 450 table rows in total (`export_max_pdf_rows`, protects against orders with very many positions).
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('customers.export_sync_xlsx_max', 300);
        $this->migrator->add('customers.export_sync_pdf_max', 200);
        $this->migrator->add('customers.export_sync_pdf_items_max', 50);
        $this->migrator->add('customers.export_max_pdf', 400);
        $this->migrator->add('customers.export_max_pdf_items', 100);
        $this->migrator->add('customers.export_max_pdf_rows', 450);
        $this->migrator->add('customers.export_max_xlsx_rows', 20000);
        $this->migrator->add('customers.export_retention_hours', 24);
        $this->migrator->add('customers.export_requests_per_hour', 10);
        $this->migrator->add('customers.export_max_pending', 3);
        $this->migrator->add('customers.export_downloads_per_hour', 30);
        $this->migrator->add('customers.export_email_link', false);
    }

    public function down(): void
    {
        foreach (['sync_xlsx_max', 'sync_pdf_max', 'sync_pdf_items_max', 'max_pdf', 'max_pdf_items', 'max_pdf_rows', 'max_xlsx_rows', 'retention_hours', 'requests_per_hour', 'max_pending', 'downloads_per_hour', 'email_link'] as $key) {
            $this->migrator->delete('customers.export_'.$key);
        }
    }
};
