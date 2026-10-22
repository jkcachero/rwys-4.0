<?php

namespace Tests\Unit;

use App\Console\Commands\OpenAiOAuthListen;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class OpenAiOAuthListenerTest extends TestCase
{
    public function test_only_origin_form_callback_get_requests_are_accepted(): void
    {
        $command = new OpenAiOAuthListen;
        $method = new ReflectionMethod($command, 'requestTarget');

        $this->assertSame('/auth/callback?state=example', $method->invoke($command, "GET /auth/callback?state=example HTTP/1.1\r\nHost: 127.0.0.1:1455\r\n\r\n"));
        foreach (['/dashboard', '/favicon.ico', '/auth/callback/extra', 'http://untrusted.example/auth/callback'] as $target) {
            $this->assertNull($method->invoke($command, "GET {$target} HTTP/1.1\r\n\r\n"));
        }
        $this->assertNull($method->invoke($command, "POST /auth/callback HTTP/1.1\r\n\r\n"));
    }

    public function test_callback_request_has_the_actual_loopback_origin_and_no_browser_session(): void
    {
        $command = new OpenAiOAuthListen;
        $method = new ReflectionMethod($command, 'callbackRequest');
        $request = $method->invoke($command, '/auth/callback?state=example&code=fictional', 54321);

        $this->assertSame('http://127.0.0.1:54321', $request->getSchemeAndHttpHost());
        $this->assertSame('127.0.0.1', $request->server('REMOTE_ADDR'));
        $this->assertSame(54321, $request->getPort());
        $this->assertSame('fictional', $request->query('code'));
        $this->assertSame([], $request->cookies->all());
    }

    public function test_header_reader_waits_for_complete_headers_and_enforces_a_byte_limit(): void
    {
        $command = new OpenAiOAuthListen;
        $method = new ReflectionMethod($command, 'readHeaders');
        foreach ([
            ["GET /auth/callback HTTP/1.1\r\nHost: 127.0.0.1\r\n\r\n", true],
            ["GET /auth/callback HTTP/1.1\r\nHost: incomplete", false],
            ["GET /auth/callback HTTP/1.1\r\nX-Oversized: ".str_repeat('x', 17000)."\r\n\r\n", false],
        ] as [$headers, $valid]) {
            $stream = fopen('php://temp', 'r+');
            fwrite($stream, $headers);
            rewind($stream);
            try {
                $result = $method->invoke($command, $stream);
                $this->assertSame($valid, is_string($result));
            } finally {
                fclose($stream);
            }
        }
    }
}
