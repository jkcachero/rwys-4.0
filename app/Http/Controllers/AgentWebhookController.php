<?php

namespace App\Http\Controllers;

use App\Jobs\ReconcileAgentWebhook;
use App\Models\AgentWebhookReceipt;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class AgentWebhookController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $raw = $request->getContent();
        if (strlen($raw) > 1048576) {
            return response()->json(['message' => 'Webhook body is too large.'], 413);
        }
        $secret = config('agent-api.zernio.webhook_secret');
        if (! is_string($secret) || $secret === '') {
            return response()->json(['message' => 'Webhook verification is unavailable.'], 503);
        }
        $signature = $request->header('X-Zernio-Signature');
        if (! is_string($signature) || preg_match('/\A[a-f0-9]{64}\z/D', $signature) !== 1
            || ! hash_equals(hash_hmac('sha256', $raw, $secret), $signature)) {
            return response()->json(['message' => 'Invalid webhook signature.'], 401);
        }
        $payload = json_decode($raw, true);
        if (! is_array($payload)) {
            return response()->json(['message' => 'Invalid webhook payload.'], 422);
        }
        $supported = in_array($payload['event'] ?? null, AgentWebhookReceipt::POST_EVENTS, true);
        $rules = ['id' => ['required', 'uuid'], 'event' => ['required', 'string', 'max:100'],
            'timestamp' => ['required', 'date'], 'test' => ['sometimes', 'boolean']];
        if ($supported) {
            $rules += [
                'post' => ['required', 'array'], 'post.id' => ['required', 'string', 'regex:/\A[a-fA-F0-9]{24}\z/D'],
                'post.content' => ['present', 'string'], 'post.status' => ['required', 'string'],
                'post.scheduledFor' => ['required', 'date'], 'post.platforms' => ['present', 'array', 'list'],
                'post.platforms.*.platform' => ['required', 'string'], 'post.platforms.*.status' => ['required', 'string'],
            ];
            if (str_starts_with($payload['event'], 'post.platform.') || $payload['event'] === 'post.tiktok.url_resolved') {
                $rules += ['account.accountId' => ['required', 'string', 'regex:/\A[a-fA-F0-9]{24}\z/D'],
                    'account.platform' => ['required', 'string'], 'account.username' => ['present', 'string'],
                    'platform.name' => ['required', 'string'], 'platform.status' => ['required', 'string']];
            }
        }
        if (Validator::make($payload, $rules)->fails()) {
            return response()->json(['message' => 'Invalid webhook payload.'], 422);
        }

        try {
            $receipt = AgentWebhookReceipt::query()->createOrFirst(['event_id' => strtolower($payload['id'])], [
                'event' => $payload['event'], 'raw_body' => $raw,
                'status' => $supported && ($payload['test'] ?? false) !== true ? 'pending' : 'ignored',
                'expires_at' => now()->addDay(),
            ]);
        } catch (Throwable) {
            return response()->json(['message' => 'Webhook persistence is unavailable.'], 503);
        }

        $connection = config('queue.default');
        $driver = is_string($connection) ? config("queue.connections.{$connection}.driver") : null;
        if ($receipt->wasRecentlyCreated && $receipt->status === 'pending'
            && in_array($driver, ['database', 'redis', 'sqs', 'beanstalkd'], true)) {
            try {
                Queue::connection($connection)->push(new ReconcileAgentWebhook($receipt->id));
            } catch (Throwable) {
                // The durable receipt remains available to the CLI if enqueueing fails.
            }
        }

        return response()->noContent();
    }
}
