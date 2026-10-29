<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * A cabinet action was refused with a message for the customer (the address limit is reached, ...). The message is
 * Russian UI text; controllers show it next to the form.
 */
class CustomerAccountException extends RuntimeException {}
