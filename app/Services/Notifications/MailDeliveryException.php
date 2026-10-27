<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use RuntimeException;

/**
 * A mail could not be sent. Built without `previous` and with masked addresses: the transport exceptions
 * contain the recipient's email, which must not reach the logs (NF-15).
 */
final class MailDeliveryException extends RuntimeException {}
