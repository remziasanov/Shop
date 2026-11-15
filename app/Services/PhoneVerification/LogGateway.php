<?php

declare(strict_types=1);

namespace App\Services\PhoneVerification;

use App\Support\PiiMask;
use Illuminate\Support\Facades\Log;

/**
 * Development and test driver (PV-02): nothing is sent, the masked number and the code go to the log (NF-15).
 * It cannot be selected when APP_ENV=production ({@see GatewayFactory}, shop:doctor).
 */
final class LogGateway implements PhoneVerificationGateway
{
    public function startFlashCall(string $phone, ?string $code): FlashCallResult
    {
        $code ??= str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);

        Log::info('Phone verification (log driver): flash call', ['phone' => PiiMask::phone($phone), 'code' => $code]);

        return new FlashCallResult('log-'.bin2hex(random_bytes(6)), $code, '0.0000');
    }

    public function sendSms(string $phone, string $text): SmsResult
    {
        Log::info('Phone verification (log driver): sms', ['phone' => PiiMask::phone($phone), 'text' => $text]);

        return new SmsResult('log-'.bin2hex(random_bytes(6)), '0.0000');
    }

    public function balance(): ?string
    {
        return null;
    }

    public function supportsCallerCode(): bool
    {
        return true;
    }
}
