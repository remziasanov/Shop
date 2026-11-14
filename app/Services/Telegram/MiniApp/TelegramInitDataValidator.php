<?php

declare(strict_types=1);

namespace App\Services\Telegram\MiniApp;

/**
 * Verifies the initData string of a Telegram Mini App (TM-02, D-102).
 *
 * The string is parsed by hand: split by `&`, then by the first `=`, values through urldecode. `parse_str` is not used,
 * because it rewrites dots and brackets in keys. The data check string holds every pair except `hash` (the `signature`
 * field stays in it), sorted by key, as `key=value` lines. secret_key = HMAC_SHA256(key "WebAppData", message bot
 * token) as raw bytes; the expected hash is the hex HMAC of the data check string under that key, compared with
 * hash_equals. The age of `auth_date` is checked only when a session is created ($checkAge).
 */
final class TelegramInitDataValidator
{
    /** `auth_date` may be this many seconds ahead of the server clock (clock skew). */
    public const FUTURE_TOLERANCE = 300;

    /**
     * @param  int  $ttlSeconds  the longest age of `auth_date` (settings, TG-01)
     *
     * @throws InvalidInitDataException
     */
    public function validate(string $initData, string $botToken, int $ttlSeconds, bool $checkAge = true): InitData
    {
        if ($botToken === '') {
            throw new InvalidInitDataException(InvalidInitDataException::MISSING_BOT);
        }

        $pairs = $this->parse($initData);
        $hash = $pairs['hash'] ?? null;

        if (! is_string($hash) || $hash === '') {
            throw new InvalidInitDataException(InvalidInitDataException::NO_HASH);
        }

        unset($pairs['hash']);
        ksort($pairs, SORT_STRING);

        $lines = [];

        foreach ($pairs as $key => $value) {
            $lines[] = $key.'='.$value;
        }

        $secretKey = hash_hmac('sha256', $botToken, 'WebAppData', true);
        $expected = hash_hmac('sha256', implode("\n", $lines), $secretKey);

        if (! hash_equals($expected, strtolower($hash))) {
            throw new InvalidInitDataException(InvalidInitDataException::BAD_SIGNATURE);
        }

        $authDate = $pairs['auth_date'] ?? '';
        $authDate = ctype_digit($authDate) ? (int) $authDate : 0;

        if ($checkAge) {
            $now = time();

            if ($authDate <= 0 || $now - $authDate > $ttlSeconds) {
                throw new InvalidInitDataException(InvalidInitDataException::EXPIRED);
            }

            if ($authDate - $now > self::FUTURE_TOLERANCE) {
                throw new InvalidInitDataException(InvalidInitDataException::FUTURE);
            }
        }

        $user = json_decode($pairs['user'] ?? '', true);

        if (! is_array($user) || ! isset($user['id']) || ! is_int($user['id']) || $user['id'] <= 0) {
            throw new InvalidInitDataException(InvalidInitDataException::NO_USER);
        }

        $startParam = $pairs['start_param'] ?? null;

        return new InitData(
            userId: $user['id'],
            firstName: $this->text($user['first_name'] ?? null),
            lastName: $this->text($user['last_name'] ?? null),
            username: $this->text($user['username'] ?? null),
            languageCode: $this->text($user['language_code'] ?? null, 10),
            allowsWriteToPm: ($user['allows_write_to_pm'] ?? false) === true,
            startParam: is_string($startParam) && $startParam !== '' ? $startParam : null,
            authDate: $authDate,
        );
    }

    /**
     * @return array<string, string>
     *
     * @throws InvalidInitDataException
     */
    private function parse(string $initData): array
    {
        if ($initData === '' || strlen($initData) > 8192) {
            throw new InvalidInitDataException(InvalidInitDataException::MALFORMED);
        }

        $pairs = [];

        foreach (explode('&', $initData) as $part) {
            $position = strpos($part, '=');

            if ($position === false || $position === 0) {
                throw new InvalidInitDataException(InvalidInitDataException::MALFORMED);
            }

            $key = urldecode(substr($part, 0, $position));

            // A repeated key would let two readers disagree about the value: reject.
            if (isset($pairs[$key])) {
                throw new InvalidInitDataException(InvalidInitDataException::MALFORMED);
            }

            $pairs[$key] = urldecode(substr($part, $position + 1));
        }

        return $pairs;
    }

    private function text(mixed $value, int $limit = 150): ?string
    {
        return is_string($value) && $value !== '' ? mb_substr($value, 0, $limit) : null;
    }
}
