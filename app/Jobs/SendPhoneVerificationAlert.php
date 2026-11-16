<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Jobs\Concerns\DeliversTelegramMessages;
use App\Mail\PhoneVerificationAlertMail;
use App\Services\Notifications\HtmlEscaper;
use App\Services\Notifications\MailDeliveryException;
use App\Services\Notifications\TelegramNotifier;
use App\Support\PiiMask;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mime\Exception\RfcComplianceException;
use Throwable;

/**
 * One warning to the shop about the phone verification provider (PV-05, PV-09): one e-mail to one address or one
 * Telegram message to the work chat. Every channel and recipient is its own job, so one failing channel does not block
 * or repeat the others. The text is built here from the topic; the parameters are amounts and codes, never numbers.
 */
class SendPhoneVerificationAlert implements ShouldBeUnique, ShouldQueue
{
    use DeliversTelegramMessages;
    use Queueable;

    public const MAIL = 'mail';

    public const TELEGRAM = 'telegram';

    public const TOPIC_PROVIDER_FAILURE = 'provider_failure';

    public const TOPIC_LOW_BALANCE = 'low_balance';

    public const TOPIC_EMPTY_BALANCE = 'empty_balance';

    public const TOPIC_DAILY_LIMIT = 'daily_limit';

    public const TOPIC_GUEST_SHARE = 'guest_share';

    public int $maxExceptions = 3;

    public int $timeout = 30;

    public int $uniqueFor = 3600;

    /**
     * @param  array<string, string>  $params  placeholders of the text (`kind`, `balance`, `threshold`, `limit`, `cap`)
     */
    public function __construct(
        public readonly string $topic,
        public readonly string $channel,
        public readonly ?string $to = null,
        public readonly array $params = [],
    ) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function uniqueId(): string
    {
        return implode(':', ['phone-verification-alert', $this->topic, $this->channel, $this->to ?? '']);
    }

    public function handle(TelegramNotifier $telegram): void
    {
        $subject = __('phone_verification.alerts.'.$this->topic.'.subject');
        $body = __('phone_verification.alerts.'.$this->topic.'.body', $this->params);

        if ($this->channel === self::TELEGRAM) {
            $this->deliverTelegram(fn () => $telegram->send('<b>'.HtmlEscaper::e($subject).'</b>'."\n".HtmlEscaper::e($body)));

            return;
        }

        if (blank($this->to)) {
            return;
        }

        try {
            Mail::to($this->to)->send(new PhoneVerificationAlertMail($subject, $body));
        } catch (TransportExceptionInterface|RfcComplianceException $e) {
            // Transport exceptions quote the recipient: rethrow a sanitized one, without `previous` (NF-15).
            throw new MailDeliveryException(PiiMask::scrubEmails(str_replace($this->to, PiiMask::email($this->to), $e::class.': '.$e->getMessage())));
        }
    }

    public function failed(Throwable $e): void
    {
        Log::warning('Phone verification alert failed', [
            'topic' => $this->topic,
            'channel' => $this->channel,
            'to' => PiiMask::email($this->to),
            'exception' => $e::class,
            'message' => PiiMask::scrubEmails($e->getMessage()),
        ]);
    }
}
