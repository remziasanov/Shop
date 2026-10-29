<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Mail\WholesaleApplicationShopMail;
use App\Models\WholesaleApplication;
use App\Services\Customers\WholesaleApplicationService;
use App\Services\Notifications\MailDeliveryException;
use App\Services\Notifications\Messages\WholesaleApplicationMessage;
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
 * One notification to the shop about a new wholesale application (CG-21): one e-mail to one address, or one Telegram
 * message. Every channel and recipient is its own job, so a failing one neither blocks nor repeats the others.
 */
class SendWholesaleApplicationNotification implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public const MAIL = 'mail';

    public const TELEGRAM = 'telegram';

    public int $tries = 3;

    public int $timeout = 30;

    /** Seconds a queued or running twin of this job blocks a duplicate. */
    public int $uniqueFor = 3600;

    public function __construct(
        public readonly int $applicationId,
        public readonly string $kind,
        public readonly ?string $to = null,
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
        return implode(':', ['wholesale', $this->applicationId, $this->kind, $this->to ?? '']);
    }

    public function handle(WholesaleApplicationService $service, TelegramNotifier $telegram): void
    {
        $application = WholesaleApplication::query()->find($this->applicationId);

        // Anonymized in the meantime (no personal data left to send) or deleted.
        if ($application === null || blank($application->company_name)) {
            return;
        }

        $warnings = $service->duplicateWarnings($application);

        if ($this->kind === self::TELEGRAM) {
            $telegram->send((new WholesaleApplicationMessage)->render($application, $warnings));

            return;
        }

        if (blank($this->to)) {
            return;
        }

        try {
            Mail::to($this->to)->send(new WholesaleApplicationShopMail($application, $warnings));
        } catch (TransportExceptionInterface|RfcComplianceException $e) {
            // Transport exceptions quote the recipient: rethrow a sanitized one, without `previous` (NF-15).
            throw new MailDeliveryException(PiiMask::scrubEmails(str_replace($this->to, PiiMask::email($this->to), $e::class.': '.$e->getMessage())));
        }
    }

    public function failed(Throwable $e): void
    {
        Log::warning('Wholesale application notification failed', [
            'application_id' => $this->applicationId,
            'kind' => $this->kind,
            'to' => PiiMask::email($this->to),
            'exception' => $e::class,
            'message' => PiiMask::scrubEmails($e->getMessage()),
        ]);
    }
}
