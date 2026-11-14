<?php

declare(strict_types=1);

namespace App\Services\Telegram\MiniApp;

/**
 * A verified initData of a Mini App (TM-02): only what the shop uses. Built by {@see TelegramInitDataValidator} after
 * the signature check, never from `initDataUnsafe`.
 */
final readonly class InitData
{
    public function __construct(
        public int $userId,
        public ?string $firstName,
        public ?string $lastName,
        public ?string $username,
        public ?string $languageCode,
        public bool $allowsWriteToPm,
        public ?string $startParam,
        public int $authDate,
    ) {}
}
