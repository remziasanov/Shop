<?php

declare(strict_types=1);

namespace App\Services\Telegram\Staff;

use RuntimeException;

/**
 * Internal signal of {@see StaffOrderActions}: the request moved while the action waited for the lock.
 */
final class StaleRequest extends RuntimeException {}
