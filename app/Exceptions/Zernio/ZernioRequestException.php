<?php

namespace App\Exceptions\Zernio;

use Illuminate\Http\JsonResponse;
use RuntimeException;

class ZernioRequestException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $responseStatus = 502,
        public readonly string $errorCode = 'provider_error',
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        $response = response()->json([
            'error' => [
                'code' => $this->errorCode,
                'message' => $this->getMessage(),
            ],
        ], $this->responseStatus);

        if ($this->retryAfter !== null) {
            $response->headers->set('Retry-After', (string) $this->retryAfter);
        }

        return $response;
    }
}
