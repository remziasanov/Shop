<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Why a promo code was refused, in the order of the checks of PM-09.
 */
enum PromoFailure: string
{
    case NotFound = 'not_found';
    case Inactive = 'inactive';
    case NotStarted = 'not_started';
    case Expired = 'expired';
    case GroupNotAllowed = 'group_not_allowed';
    case OnlyRegistered = 'only_registered';
    case Exhausted = 'exhausted';
    case PerCustomerLimit = 'per_customer_limit';
    case MinOrder = 'min_order';
    case NoEligibleItems = 'no_eligible_items';
    case NoDiscount = 'no_discount';
}
