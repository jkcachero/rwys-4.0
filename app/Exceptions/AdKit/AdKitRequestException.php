<?php

namespace App\Exceptions\AdKit;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class AdKitRequestException extends RuntimeException
{
    public readonly string $errorCode;

    public readonly int $responseStatus;

    public function __construct(string $errorCode = 'adkit_provider_error', public readonly ?int $upstreamStatus = null)
    {
        [$this->errorCode, $message, $this->responseStatus] = match ($errorCode) {
            'adkit_configuration_error' => [$errorCode, 'AdKit configuration is unavailable.', 503],
            'adkit_connection_invalid' => [$errorCode, 'AdKit credentials are unavailable or expired.', 503],
            'adkit_mapping_invalid' => [$errorCode, 'AdKit account mapping is unavailable.', 503],
            'adkit_unreachable' => [$errorCode, 'AdKit could not be reached.', 503],
            'adkit_rate_limited' => [$errorCode, 'AdKit is temporarily rate limited.', 503],
            'adkit_authentication_failed' => [$errorCode, 'AdKit authentication failed.', 502],
            'adkit_invalid_response' => [$errorCode, 'AdKit returned an invalid response.', 502],
            'adkit_response_too_large' => [$errorCode, 'AdKit returned a response exceeding the size limit.', 502],
            'adkit_invalid_request' => [$errorCode, 'AdKit request is invalid.', 502],
            'adkit_idempotency_conflict' => [$errorCode, 'The idempotency key was already used for a different request.', 409],
            'adkit_operation_pending' => [$errorCode, 'The AdKit operation is still pending.', 409],
            'adkit_unconfirmed' => [$errorCode, 'AdKit execution could not be confirmed. Do not retry with a different Idempotency-Key.', 503],
            'adkit_draft_unsupported' => [$errorCode, 'AdKit draft support could not be confirmed from the provider response.', 503],
            default => ['adkit_provider_error', 'AdKit could not complete the request.', 502],
        };

        parent::__construct($message);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'error' => $this->errorCode,
            'message' => $this->getMessage(),
        ], $this->responseStatus);
    }
}
