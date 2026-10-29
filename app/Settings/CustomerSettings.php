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

    public static function group(): string
    {
        return 'customers';
    }
}
