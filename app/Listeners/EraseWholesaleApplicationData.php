<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\CustomerAnonymizing;
use App\Services\Customers\WholesaleApplicationService;

/**
 * CA-17: the anonymization of a customer erases the personal fields of their wholesale applications. Synchronous on
 * purpose (never queued): a failure rolls the whole anonymization back.
 */
final class EraseWholesaleApplicationData
{
    public function __construct(private readonly WholesaleApplicationService $applications) {}

    public function handle(CustomerAnonymizing $event): void
    {
        $this->applications->anonymizeFor($event->customer);
        $this->applications->forgetCount();
    }
}
