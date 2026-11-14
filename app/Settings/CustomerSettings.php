<?php

declare(strict_types=1);

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * «Покупатели и регистрация» (CA-23, CA-01, CA-04). Settings of the customer accounts; the default group of new
 * customers is the `is_default_for_new` flag of a customer group (CG-11), edited on the same settings page.
 */
class CustomerSettings extends Settings
{
    /** The «Войти» / «Личный кабинет» links and the registration form are available (CA-19, CA-23). */
    public bool $registration_enabled;

    /** CA-01: a guest may place an order, a reservation or an inquiry. */
    public bool $allow_guest_checkout;

    /** CA-04: life of a one-time code, minutes. */
    public int $code_ttl_minutes;

    /** CA-04: wrong entries allowed per code. */
    public int $code_max_attempts;

    /** CA-04: minimum interval between two codes to the same address, seconds. */
    public int $code_resend_seconds;

    /** CA-24: an unconfirmed account older than this is removed by shop:purge-unverified-customers. */
    public int $unverified_retention_days;

    /** CA-16: days between the deletion request and the anonymization (the customer can cancel). */
    public int $deletion_grace_days;

    /** CA-02: minimum password length. */
    public int $password_min_length;

    /** CA-25: warning in every mail with a code. */
    public string $code_mail_warning;

    /** CA-25: free text under the code in every mail with a code (may be empty). */
    public string $code_mail_footer;

    /** CX-04: an Excel export of at most this many requests is built in the web request. */
    public int $export_sync_xlsx_max;

    /** CX-04: a PDF export without items of at most this many requests is built in the web request. */
    public int $export_sync_pdf_max;

    /** CX-04: the same, with the items of the orders. */
    public int $export_sync_pdf_items_max;

    /** CX-05: hard limit of requests in a PDF without items. */
    public int $export_max_pdf;

    /** CX-05: hard limit of requests in a PDF with items. */
    public int $export_max_pdf_items;

    /** CX-05: hard limit of table rows of a PDF (requests plus, with items, their positions): dompdf memory grows with rows. */
    public int $export_max_pdf_rows;

    /** CX-05: hard limit of rows of an Excel file (requests plus items). */
    public int $export_max_xlsx_rows;

    /** CX-07: hours a finished file can be downloaded. */
    public int $export_retention_hours;

    /** CX-09: export requests per customer per hour. */
    public int $export_requests_per_hour;

    /** CX-09: unfinished exports a customer may have at once. */
    public int $export_max_pending;

    /** CX-09: file downloads per customer per hour. */
    public int $export_downloads_per_hour;

    /** CX-06: e-mail with the link when a background export is ready. */
    public bool $export_email_link;

    public static function group(): string
    {
        return 'customers';
    }
}
