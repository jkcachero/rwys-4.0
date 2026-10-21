<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

#[Signature('openai:oauth-listen {--host=127.0.0.1} {--port=1455}')]
#[Description('Serve one OpenAI OAuth callback on the local loopback interface')]
class OpenAiOAuthListen extends Command
{
    public function handle(): int
    {
        $host = (string) $this->option('host');
        $port = (int) $this->option('port');

        if ($host !== '127.0.0.1') {
            $this->error('The OAuth callback listener must bind to 127.0.0.1.');

            return self::FAILURE;
        }

        if ($port !== 1455) {
            $this->error('The OpenAI OAuth redirect URI is fixed to port 1455.');

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

        stream_set_timeout($server, 600);
        $this->info("Listening once on {$host}:{$port} for the OpenAI OAuth callback.");

        $connection = stream_socket_accept($server, 600);
        fclose($server);

        if ($connection === false) {
            $this->error('No OAuth callback was received before the listener expired.');

            return self::FAILURE;
        }

        stream_set_timeout($connection, 10);
        $rawRequest = fread($connection, 16384);
        $requestTarget = $this->requestTarget($rawRequest);

        if ($requestTarget === null) {
            $this->writeResponse($connection, new Response('Bad callback request.', 400));
            fclose($connection);

            return self::FAILURE;
        }

        parse_str((string) parse_url($requestTarget, PHP_URL_QUERY), $query);
        $request = Request::create($requestTarget, 'GET', $query);
        $request->server->set('REMOTE_ADDR', '127.0.0.1');
        $request->server->set('SERVER_PORT', '1455');

        try {
            $response = app()->handle($request);
        } catch (\Throwable) {
            $response = new Response('OAuth callback could not be processed.', 500);
        }

        $this->writeResponse($connection, $response);
        fclose($connection);

        return self::SUCCESS;
    }

    private function requestTarget(string $rawRequest): ?string
    {
        $firstLine = strtok($rawRequest, "\r\n");

        if (! is_string($firstLine) || ! preg_match('/^GET (\/[^ ]*) HTTP\/1\.[01]$/', $firstLine, $matches)) {
            return null;
        }

        return $matches[1];
    }

    private function writeResponse($connection, Response $response): void
    {
        $body = (string) $response->getContent();
        $reason = Response::$statusTexts[$response->getStatusCode()] ?? '';
        $headers = [
            "HTTP/1.1 {$response->getStatusCode()} {$reason}",
            'Content-Type: '.$response->headers->get('Content-Type', 'text/plain; charset=UTF-8'),
            'Content-Length: '.strlen($body),
            'Connection: close',
        ];

        if ($location = $response->headers->get('Location')) {
            $headers[] = 'Location: '.$location;
        }

        fwrite($connection, implode("\r\n", $headers)."\r\n\r\n{$body}");
    }
}
