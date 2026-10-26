<?php

declare(strict_types=1);

namespace App\Exceptions;

use DomainException;

/**
 * A sales rule was violated; the message is a Russian text that is safe to show to the customer or manager.
 */
class SalesException extends DomainException {}
