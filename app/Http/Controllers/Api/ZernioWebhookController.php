<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ReconcileZernioWebhookReceipt;
use App\Models\ZernioWebhookReceipt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

class ZernioWebhookController extends Controller
{
    private const MAX_BODY_BYTES = 1_048_576;

    public function __invoke(Request $request): Response|JsonResponse
    {
        $rawPayload = $request->getContent();

        if (strlen($rawPayload) > self::MAX_BODY_BYTES) {
            return response()->json(['error' => 'payload_too_large'], 413);
        }

        $secret = config('services.zernio.webhook_secret');

        if (! is_string($secret) || $secret === '') {
            return response()->json(['error' => 'webhook_unavailable'], 503);
        }

        $signature = $request->header('X-Zernio-Signature');

        if (! is_string($signature)
            || preg_match('/^[a-f0-9]{64}$/D', $signature) !== 1
            || ! hash_equals(hash_hmac('sha256', $rawPayload, $secret), $signature)) {
            return response()->json(['error' => 'invalid_signature'], 401);
        }

        try {
            $payload = json_decode($rawPayload, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return response()->json(['error' => 'invalid_payload'], 422);
        }

        $eventId = is_array($payload) ? ($payload['id'] ?? null) : null;
        $eventType = is_array($payload) ? ($payload['type'] ?? null) : null;

        if (! is_string($eventId)
            || ! Str::isUuid($eventId)
            || ! is_string($eventType)
            || $eventType === ''
            || strlen($eventType) > 120) {
            return response()->json(['error' => 'invalid_payload'], 422);
        }

        $receivedAt = now();
        $receipt = ZernioWebhookReceipt::query()->createOrFirst(
            ['event_id' => $eventId],
            [
                'event_type' => $eventType,
                'payload_hash' => hash('sha256', $rawPayload),
                'raw_payload' => $rawPayload,
                'status' => 'received',
                'received_at' => $receivedAt,
                'unmatched_until' => $receivedAt->copy()->addDay(),
                'payload_purge_after' => $receivedAt->copy()->addDays(7),
            ],
        );

        if ($receipt->wasRecentlyCreated) {
            ReconcileZernioWebhookReceipt::dispatch($receipt->id);
        }

        return response()->noContent();
    }
}
