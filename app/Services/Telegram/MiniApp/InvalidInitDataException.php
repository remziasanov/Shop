<?php

declare(strict_types=1);

namespace App\Services\Telegram\MiniApp;

use RuntimeException;

/**
 * The initData string of a Mini App did not pass the check (TM-02). The reason is for logs and tests only; the visitor
 * always sees the same «Откройте магазин заново из бота» page.
 */
final class InvalidInitDataException extends RuntimeException
{
    public const MALFORMED = 'malformed';

    public const NO_HASH = 'no_hash';

    public const BAD_SIGNATURE = 'bad_signature';

    public const EXPIRED = 'expired';

    public const FUTURE = 'future';

    public const NO_USER = 'no_user';

    public const MISSING_BOT = 'missing_bot';

    public function __construct(public readonly string $reason)
    {
        parent::__construct('Invalid Telegram initData: '.$reason);
    }
}
