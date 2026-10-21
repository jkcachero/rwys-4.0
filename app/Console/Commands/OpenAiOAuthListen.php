<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

#[Signature('openai:oauth-listen {--host=127.0.0.1} {--port=} {--timeout=600}')]
#[Description('Serve one OpenAI OAuth callback on the local loopback interface')]
class OpenAiOAuthListen extends Command
{
    public function handle(): int
    {
        $host = (string) $this->option('host');
        $redirect = parse_url((string) config('workspace.oauth.redirect_uri'));
        $configuredPort = (int) ($redirect['port'] ?? 80);
        $port = $this->option('port') !== null ? (int) $this->option('port') : $configuredPort;
        $timeout = filter_var($this->option('timeout'), FILTER_VALIDATE_INT);

        if ($host !== '127.0.0.1') {
            $this->error('The OAuth callback listener must bind to 127.0.0.1.');

            return self::FAILURE;
        }

        if (($redirect['scheme'] ?? null) !== 'http' || ($redirect['host'] ?? null) !== '127.0.0.1'
            || ($redirect['path'] ?? null) !== '/auth/callback' || isset($redirect['query']) || isset($redirect['fragment'])
            || $port < 1024 || $port > 65535 || $port !== $configuredPort) {
            $this->error('Configure an HTTP 127.0.0.1 callback with path /auth/callback and the same port as this listener.');

            return self::FAILURE;
        }

        if ($timeout === false || $timeout < 1 || $timeout > 600) {
            $this->error('The listener timeout must be between 1 and 600 seconds.');

            return self::FAILURE;
        }

        $server = @stream_socket_server(
            "tcp://{$host}:{$port}",
            $errorCode,
            $errorMessage,
        );

        if ($server === false) {
            $this->error("Unable to bind {$host}:{$port}: {$errorMessage} ({$errorCode})");

            return self::FAILURE;
        }

        $deadline = time() + $timeout;
        $this->info("Listening on {$host}:{$port} for up to {$timeout} seconds. Keep this terminal open during sign-in.");
        try {
            while (time() < $deadline) {
                $connection = @stream_socket_accept($server, min(10, max(1, $deadline - time())));
                if ($connection === false) {
                    continue;
                }

                try {
                    stream_set_timeout($connection, min(10, max(1, $deadline - time())));
                    $headers = $this->readHeaders($connection);
                    $target = $headers !== null ? $this->requestTarget($headers) : null;
                    if ($target === null) {
                        $this->writeResponse($connection, new Response('Only complete GET /auth/callback requests are accepted.', 400));

                        continue;
                    }

                    try {
                        $response = app()->handle($this->callbackRequest($target, $port));
                    } catch (\Throwable) {
                        $response = new Response('OAuth callback could not be processed. Return to your workspace and start a new attempt.', 500);
                    }
                    $this->writeResponse($connection, $response);

                    if ($response->isSuccessful()) {
                        $this->info('Authorization callback handled. Return to your workspace for the connection result.');

                        return self::SUCCESS;
                    }
                } finally {
                    fclose($connection);
                }
            }
        } finally {
            fclose($server);
        }

        $this->error('The callback listener expired. Restart it before a new sign-in attempt.');

        return self::FAILURE;
    }

    /** @param resource $connection */
    private function readHeaders(mixed $connection): ?string
    {
        $headers = '';
        while (! feof($connection) && strlen($headers) < 16384) {
            $chunk = fread($connection, min(1024, 16384 - strlen($headers)));
            if ($chunk === false || $chunk === '') {
                return null;
            }
            $headers .= $chunk;
            $end = strpos($headers, "\r\n\r\n");
            if ($end !== false) {
                return substr($headers, 0, $end + 4);
            }
            if (stream_get_meta_data($connection)['timed_out'] ?? false) {
                return null;
            }
        }

        return null;
    }

    private function callbackRequest(string $target, int $port): Request
    {
        return Request::create('http://127.0.0.1:'.$port.$target, 'GET', server: [
            'REMOTE_ADDR' => '127.0.0.1',
            'SERVER_PORT' => (string) $port,
        ]);
    }

    private function requestTarget(string $rawRequest): ?string
    {
        $firstLine = strtok($rawRequest, "\r\n");

        if (! is_string($firstLine) || ! preg_match('/^GET (\/[^ ]*) HTTP\/1\.[01]$/', $firstLine, $matches)
            || str_contains($matches[1], '#') || parse_url($matches[1], PHP_URL_PATH) !== '/auth/callback') {
            return null;
        }

        return $matches[1];
    }

    /** @param resource $connection */
    private function writeResponse(mixed $connection, Response $response): void
    {
        $body = (string) $response->getContent();
        $reason = Response::$statusTexts[$response->getStatusCode()] ?? '';
        $headers = [
            "HTTP/1.1 {$response->getStatusCode()} {$reason}",
            'Content-Type: '.$response->headers->get('Content-Type', 'text/plain; charset=UTF-8'),
            'Content-Length: '.strlen($body),
            'Connection: close',
            'Cache-Control: no-store',
            'Referrer-Policy: no-referrer',
        ];

        if ($policy = $response->headers->get('Content-Security-Policy')) {
            $headers[] = 'Content-Security-Policy: '.$policy;
        }

        $output = implode("\r\n", $headers)."\r\n\r\n{$body}";
        while ($output !== '') {
            $written = @fwrite($connection, $output);
            if ($written === false || $written === 0) {
                break;
            }
            $output = substr($output, $written);
        }
    }
}
