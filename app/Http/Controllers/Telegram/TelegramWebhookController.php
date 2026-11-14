<?php

declare(strict_types=1);

namespace App\Http\Controllers\Telegram;

use App\Http\Controllers\Controller;
use App\Models\TelegramUpdate;
use App\Services\Telegram\TelegramUpdateHandler;
use App\Settings\TelegramSettings;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * POST /telegram/webhook (TG-04). Outside the web group: no session, cookies or CSRF. The body of an update is never
 * stored or logged; the answer is always 200 once the update id is recorded, so Telegram does not redeliver it.
 */
class TelegramWebhookController extends Controller
{
    /** Updates are small; anything above 1 MB is not from Telegram. */
    public const MAX_BYTES = 1048576;

    public function __invoke(Request $request, TelegramSettings $settings, TelegramUpdateHandler $handler): Response|JsonResponse
    {
        $secret = (string) $settings->webhook_secret;
        $given = (string) $request->header('X-Telegram-Bot-Api-Secret-Token', '');

        if ($secret === '' || ! hash_equals($secret, $given)) {
            Log::warning('Telegram webhook rejected: bad secret token', ['ip' => $request->ip()]);

            return response('', 403);
        }

        if ((int) $request->header('Content-Length', '0') > self::MAX_BYTES || strlen($request->getContent()) > self::MAX_BYTES) {
            return response('', 400);
        }

        $update = json_decode($request->getContent(), true);

        if (! is_array($update) || ! isset($update['update_id']) || ! is_int($update['update_id'])) {
            return response('', 400);
        }

        try {
            TelegramUpdate::query()->create([
                'update_id' => $update['update_id'],
                'type' => TelegramUpdateHandler::typeOf($update),
                'received_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) { // a redelivery: already processed
            return response('', 200);
        }

        try {
            $reply = $handler->handle($update);
        } catch (Throwable $e) {
            // No message and no body: both may carry personal data (NF-15).
            Log::error('Telegram update processing failed', [
                'update_id' => $update['update_id'],
                'exception' => $e::class,
                'where' => basename($e->getFile()).':'.$e->getLine(),
            ]);

            return response('', 200);
        }

        return $reply === null ? response('', 200) : response()->json($reply);
    }
}
