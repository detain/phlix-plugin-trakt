<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins\Scrobbler\Trakt;

use Phlix\Plugins\Scrobbler\Trakt\HttpClient;
use Phlix\Plugins\Scrobbler\Trakt\TraktApiException;
use Phlix\Plugins\Scrobbler\Trakt\TraktAuthenticationException;
use Phlix\Plugins\Scrobbler\Trakt\TraktRateLimitException;
use PHPUnit\Framework\TestCase;
use Workerman\Http\Client;
use Workerman\Worker;

/**
 * Coverage for HttpClient::requestAsync() — the cooperative-wait async branch.
 *
 * The Workerman classes are host-supplied by phlix-server, so the stubs under
 * tests/stubs/Workerman/ drive the branch deterministically (no network, no
 * swoole needed) and, in real-IO mode, perform a genuine HTTP round trip
 * against a real local server so headers like Retry-After come from actual
 * wire bytes rather than hand-built fixtures.
 *
 * The async branch is taken when a Workerman event loop is "running"; each
 * test sets Worker::$globalEvent and tearDown resets it (plus the stub state)
 * so later cURL-path tests in other files are unaffected by test ordering.
 */
final class HttpClientAsyncTest extends TestCase
{
    /** @var int|null Swoole hook flags to restore in tearDown. */
    private ?int $savedHookFlags = null;

    protected function setUp(): void
    {
        // Force the async branch: eventLoopRunning() requires a non-null
        // Workerman event loop marker.
        Worker::$globalEvent = new \stdClass();
        Client::reset();
    }

    protected function tearDown(): void
    {
        // Critical: reset the event-loop marker and the stub statics, otherwise
        // later tests in other files would take the async path and break.
        Worker::$globalEvent = null;
        Client::reset();

        if ($this->savedHookFlags !== null && \extension_loaded('swoole')) {
            \Swoole\Runtime::enableCoroutine($this->savedHookFlags);
            $this->savedHookFlags = null;
        }
    }

    // --- deterministic: async branch, no network, no swoole required ---------

    public function testRequestTakesAsyncBranchWhenEventLoopIsRunning(): void
    {
        Client::$responses['https://api.trakt.tv/users/me'] = [
            'status' => 200,
            'headers' => [],
            'body' => '{"ok":true}',
        ];

        $result = (new HttpClient(timeout: 1))->get('https://api.trakt.tv/users/me');

        $this->assertSame(['ok' => true], $result);
        $this->assertNotNull(Client::$lastRequest, 'the async transport must have been used');
        $this->assertSame('https://api.trakt.tv/users/me', Client::$lastRequest['url']);
        $this->assertSame('GET', Client::$lastRequest['options']['method']);
    }

    public function testAsyncGetSuccessDecodesJsonBody(): void
    {
        Client::$responses['https://api.trakt.tv/movies/1'] = [
            'status' => 200,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => '{"id":1,"title":"Arrival"}',
        ];

        $result = (new HttpClient(timeout: 1))->get('https://api.trakt.tv/movies/1');

        $this->assertSame(['id' => 1, 'title' => 'Arrival'], $result);
    }

    public function testAsyncPostSendsJsonEncodedBody(): void
    {
        Client::$responses['https://api.trakt.tv/sync/history'] = [
            'status' => 200,
            'headers' => [],
            'body' => '{"added":1}',
        ];

        $result = (new HttpClient(timeout: 1))->post(
            'https://api.trakt.tv/sync/history',
            ['movies' => [['ids' => ['trakt' => 1]]]]
        );

        $this->assertSame(['added' => 1], $result);
        $this->assertNotNull(Client::$lastRequest);
        $this->assertSame('POST', Client::$lastRequest['options']['method']);
        $this->assertSame('{"movies":[{"ids":{"trakt":1}}]}', Client::$lastRequest['options']['data']);
    }

    public function testAsyncUnauthorizedThrowsAuthenticationException(): void
    {
        Client::$responses['https://api.trakt.tv/users/me'] = [
            'status' => 401,
            'headers' => [],
            'body' => '',
        ];

        $client = new HttpClient(timeout: 1);

        $this->expectException(TraktAuthenticationException::class);
        $client->get('https://api.trakt.tv/users/me');
    }

    public function testAsyncRateLimitedReadsRetryAfterHeader(): void
    {
        Client::$responses['https://api.trakt.tv/movies/popular'] = [
            'status' => 429,
            'headers' => ['Retry-After' => '30'],
            'body' => '{"error":"slow down"}',
        ];

        $client = new HttpClient(timeout: 1);

        try {
            $client->get('https://api.trakt.tv/movies/popular');
            $this->fail('Expected TraktRateLimitException');
        } catch (TraktRateLimitException $e) {
            $this->assertSame('slow down', $e->getMessage());
            $this->assertSame(429, $e->getCode());
            $this->assertSame(30, $e->retryAfter);
        }
    }

    public function testAsyncGenericServerErrorThrowsApiException(): void
    {
        Client::$responses['https://api.trakt.tv/movies/popular'] = [
            'status' => 500,
            'headers' => [],
            'body' => '',
        ];

        $client = new HttpClient(timeout: 1);

        try {
            $client->get('https://api.trakt.tv/movies/popular');
            $this->fail('Expected TraktApiException');
        } catch (TraktApiException $e) {
            $this->assertSame('HTTP 500', $e->getMessage());
            $this->assertSame(500, $e->getCode());
            $this->assertNotInstanceOf(TraktRateLimitException::class, $e);
        }
    }

    public function testAsyncTransportErrorSurfacesAsApiException(): void
    {
        Client::$error = new \RuntimeException('Connection refused');

        $client = new HttpClient(timeout: 1);

        try {
            $client->get('https://api.trakt.tv/unreachable');
            $this->fail('Expected TraktApiException');
        } catch (TraktApiException $e) {
            $this->assertStringStartsWith('HTTP error:', $e->getMessage());
        }
    }

    public function testAsyncTimeoutSurfacesAsApiException(): void
    {
        Client::$neverRespond = true;

        $client = new HttpClient(timeout: 1);

        $this->expectException(TraktApiException::class);
        $this->expectExceptionMessage('HTTP request timed out after 1s');

        $client->get('https://api.trakt.tv/slow');
    }

    public function testRequestAsyncPrivateMethodExists(): void
    {
        $reflection = new \ReflectionClass(HttpClient::class);

        $this->assertTrue($reflection->hasMethod('requestAsync'), 'requestAsync must exist');
        $this->assertTrue($reflection->getMethod('requestAsync')->isPrivate());
    }

    // --- real-wire: genuine HTTP round trip against a real local server -------

    public function testRealWire429RetryAfterComesFromTheWire(): void
    {
        if (!\extension_loaded('swoole')) {
            $this->markTestSkipped('ext-swoole not available');
        }

        $this->savedHookFlags = \Swoole\Runtime::getHookFlags();
        \Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);
        Client::$realIo = true;

        \Swoole\Coroutine\run(function (): void {
            $server = $this->startHttpServer(
                'HTTP/1.1 429 Too Many Requests',
                ['Retry-After' => '42'],
                '{"error":"rate limited"}'
            );

            try {
                $client = new HttpClient(timeout: 2);

                try {
                    $client->get('http://127.0.0.1:' . $server['port'] . '/rate');
                    $this->fail('Expected TraktRateLimitException');
                } catch (TraktRateLimitException $e) {
                    $this->assertSame(429, $e->getCode());
                    $this->assertSame(42, $e->retryAfter, 'Retry-After read from real wire bytes');
                    $this->assertSame('rate limited', $e->getMessage());
                }
            } finally {
                // Close the listening socket: this wakes the blocked accept()
                // in the server coroutine, which then exits and lets the
                // Co\run container terminate.
                $server['socket']->close();
            }
        });
    }

    public function testRealWire200ResponseDecodesJsonBody(): void
    {
        if (!\extension_loaded('swoole')) {
            $this->markTestSkipped('ext-swoole not available');
        }

        $this->savedHookFlags = \Swoole\Runtime::getHookFlags();
        \Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);
        Client::$realIo = true;

        \Swoole\Coroutine\run(function (): void {
            $server = $this->startHttpServer(
                'HTTP/1.1 200 OK',
                ['Content-Type' => 'application/json'],
                '{"id":7,"title":"Arrival"}'
            );

            try {
                $client = new HttpClient(timeout: 2);
                $result = $client->get('http://127.0.0.1:' . $server['port'] . '/movie');

                $this->assertSame(['id' => 7, 'title' => 'Arrival'], $result);
            } finally {
                // Close the listening socket so the server coroutine exits and
                // the Co\run container can terminate (see the 429 test above).
                $server['socket']->close();
            }
        });
    }

    /**
     * Start a real plain-HTTP server on an ephemeral local port.
     *
     * Must be called from inside a Swoole coroutine: the accept loop runs as a
     * child coroutine, so the caller MUST close the returned socket once the
     * round trip is done (a finally block is the natural place). Closing the
     * listening socket wakes the blocked accept(), the loop exits, and the
     * enclosing Co\run container can terminate — without the close it would
     * wait on the accept loop forever (Co\run waits for every child coroutine).
     *
     * @param string $statusLine Status line, e.g. "HTTP/1.1 200 OK"
     * @param array<string, string> $headers Extra headers (Content-Length and
     *        Connection: close are appended automatically)
     * @param string $body Response body
     *
     * @return array{port: int, socket: \Swoole\Coroutine\Socket} The ephemeral
     *         port and the live listening socket the caller must close.
     */
    private function startHttpServer(string $statusLine, array $headers, string $body): array
    {
        $server = new \Swoole\Coroutine\Socket(AF_INET, SOCK_STREAM, 0);
        if (!$server->bind('127.0.0.1', 0) || !$server->listen()) {
            throw new \RuntimeException('Failed to bind/listen the test HTTP server');
        }

        $info = $server->getsockname();
        $port = (int) $info['port'];

        $response = $statusLine . "\r\n";
        foreach ($headers as $name => $value) {
            $response .= $name . ': ' . $value . "\r\n";
        }
        $response .= 'Content-Length: ' . strlen($body) . "\r\n";
        $response .= "Connection: close\r\n";
        $response .= "\r\n" . $body;

        \Swoole\Coroutine\go(static function () use ($server, $response): void {
            while (true) {
                $conn = $server->accept();
                if ($conn === false) {
                    // Listening socket closed by the test -> nothing to serve.
                    break;
                }

                \Swoole\Coroutine\go(static function () use ($conn, $response): void {
                    $data = '';
                    while (true) {
                        $chunk = $conn->recv(8192, 1.0);
                        if ($chunk === '' || $chunk === false) {
                            break;
                        }
                        $data .= $chunk;
                        if (str_contains($data, "\r\n\r\n")) {
                            break;
                        }
                    }

                    $conn->send($response);
                    $conn->close();
                });
            }
        });

        return ['port' => $port, 'socket' => $server];
    }
}
