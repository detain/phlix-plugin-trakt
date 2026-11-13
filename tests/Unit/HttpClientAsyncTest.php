<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins\Scrobbler\Trakt;

use Phlix\Common\Http\EventLoopTls;
use Phlix\Common\Runtime\WorkerContext;
use Phlix\Plugins\Scrobbler\Trakt\HttpClient;
use Phlix\Plugins\Scrobbler\Trakt\TraktApiException;
use Phlix\Plugins\Scrobbler\Trakt\TraktAuthenticationException;
use Phlix\Plugins\Scrobbler\Trakt\TraktRateLimitException;
use PHPUnit\Framework\TestCase;
use Workerman\Http\Client;

/**
 * Coverage for HttpClient::requestAsync() — the cooperative-wait async branch.
 *
 * The Workerman classes are host-supplied by phlix-server, so the stubs under
 * tests/stubs/Workerman/ drive the branch deterministically (no network, no
 * swoole needed) and, in real-IO mode, perform a genuine HTTP round trip
 * against a real local server so headers like Retry-After come from actual
 * wire bytes rather than hand-built fixtures.
 *
 * PG-125 reworked the branch gate: the async transport is chosen only when
 * the host helpers report a running event loop INSIDE a coroutine and the URL
 * is not https-under-the-Swoole-loop. Those helpers (WorkerContext,
 * EventLoopTls) are flag-driven stubs registered by tests/bootstrap.php, so
 * setUp turns the cooperative context on for this file and tearDown resets
 * every flag — later cURL-path tests in other files stay unaffected.
 */
final class HttpClientAsyncTest extends TestCase
{
    /** @var int|null Swoole hook flags to restore in tearDown. */
    private ?int $savedHookFlags = null;

    protected function setUp(): void
    {
        // Force the async branch: the gate (asyncTransportUsable) requires a
        // live loop + coroutine context and no Swoole-TLS stall.
        WorkerContext::$eventLoopRunning = true;
        WorkerContext::$inCoroutine = true;
        EventLoopTls::$swooleLoopActive = false;
        Client::reset();
    }

    protected function tearDown(): void
    {
        // Critical: reset the gate flags and the stub statics, otherwise
        // later tests in other files would take the async path and break.
        WorkerContext::$eventLoopRunning = false;
        WorkerContext::$inCoroutine = false;
        EventLoopTls::$swooleLoopActive = false;
        Client::reset();

        if ($this->savedHookFlags !== null && \extension_loaded('swoole')) {
            \Swoole\Runtime::enableCoroutine($this->savedHookFlags);
            $this->savedHookFlags = null;
        }
    }

    // --- PG-125: the gate itself -----------------------------------------------

    /**
     * The production defect shape: a live worker event loop with the call
     * arriving from a non-coroutine Timer callback. The old gate took the
     * async path here and busy-pumped to a false timeout while no socket
     * callback could run; the fixed gate must hand the request to the
     * blocking cURL transport instead (which against this dead local port
     * fails fast and loudly, proving which transport answered).
     */
    public function testLoopMarkerAloneOutsideCoroutineFallsBackToBlockingCurl(): void
    {
        WorkerContext::$inCoroutine = false;

        try {
            (new HttpClient(timeout: 1))->get('https://127.0.0.1:1/pg125');
            $this->fail('Expected the blocking transport to fail fast on the dead port');
        } catch (TraktApiException $e) {
            $this->assertStringContainsString('cURL error:', $e->getMessage());
        }

        $this->assertNull(Client::$lastRequest, 'the async transport must NOT have been used');
    }

    public function testNoEventLoopFallsBackToBlockingCurlEvenInsideCoroutine(): void
    {
        WorkerContext::$eventLoopRunning = false;

        try {
            (new HttpClient(timeout: 1))->get('https://127.0.0.1:1/pg125');
            $this->fail('Expected the blocking transport to fail fast on the dead port');
        } catch (TraktApiException $e) {
            $this->assertStringContainsString('cURL error:', $e->getMessage());
        }

        $this->assertNull(Client::$lastRequest, 'the async transport must NOT have been used');
    }

    /**
     * https while the Swoole loop drives the process stalls async TLS reads
     * (host EventLoopTls) — even in a coroutine such a URL takes blocking
     * cURL; the plain-http sibling URL still reaches the async transport
     * (visible via the HTTPS-only refusal raised from inside requestAsync).
     */
    public function testHttpsUnderSwooleLoopFallsBackWhilePlainHttpStaysAsync(): void
    {
        EventLoopTls::$swooleLoopActive = true;

        try {
            (new HttpClient(timeout: 1))->get('https://127.0.0.1:1/pg125');
            $this->fail('Expected the blocking transport to fail fast on the dead port');
        } catch (TraktApiException $e) {
            $this->assertStringContainsString('cURL error:', $e->getMessage());
        }

        $this->assertNull(Client::$lastRequest);

        $this->expectException(TraktApiException::class);
        $this->expectExceptionMessageMatches('/Refusing non-HTTPS async request/');

        (new HttpClient(timeout: 1))->get('http://api.trakt.tv/users/me');
    }

    /**
     * PG-125 pooling: one client per TLS peer host, reused across requests —
     * never a fresh construction per round trip.
     */
    public function testAsyncClientsArePooledPerHostNotPerRequest(): void
    {
        $client = new HttpClient(timeout: 1);
        foreach (['https://api.trakt.tv/users/me', 'https://api.trakt.tv/users/watched'] as $url) {
            Client::$responses[$url] = ['status' => 200, 'headers' => [], 'body' => '[]'];
            $client->get($url);
        }
        $this->assertSame(1, Client::$constructs, 'same host must reuse the pooled client');

        Client::$responses['https://login.trakt.tv/oauth/token'] = [
            'status' => 200,
            'headers' => [],
            'body' => '{}',
        ];
        $client->get('https://login.trakt.tv/oauth/token');
        $this->assertSame(2, Client::$constructs, 'a distinct TLS peer gets its own pooled client');
    }

    /**
     * The pooled client is built exactly once per instance and must carry the
     * strict TLS context on every peer host it serves.
     */
    public function testPooledClientCarriesStrictTlsContextForItsPeerHost(): void
    {
        Client::$responses['https://login.trakt.tv/oauth/token'] = [
            'status' => 200,
            'headers' => [],
            'body' => '{}',
        ];

        (new HttpClient(timeout: 1))->get('https://login.trakt.tv/oauth/token');

        $ssl = Client::$lastConfig['context']['ssl'] ?? null;
        $this->assertIsArray($ssl);
        $this->assertSame('login.trakt.tv', $ssl['peer_name'], 'peer_name must follow the request host');
    }

    /**
     * TRK-2: the effective async TLS context is strict and redirects are
     * killed explicitly (client config and per-request option).
     */
    public function testAsyncTransportPinsStrictTlsContextAndKillsRedirects(): void
    {
        Client::$responses['https://api.trakt.tv/users/me'] = [
            'status' => 200,
            'headers' => [],
            'body' => '{}',
        ];

        (new HttpClient(timeout: 1))->get('https://api.trakt.tv/users/me');

        $ssl = Client::$lastConfig['context']['ssl'] ?? null;
        $this->assertIsArray($ssl);
        $this->assertTrue($ssl['verify_peer']);
        $this->assertTrue($ssl['verify_peer_name']);
        $this->assertFalse($ssl['allow_self_signed']);
        $this->assertSame('api.trakt.tv', $ssl['peer_name']);
        $this->assertSame(0, Client::$lastConfig['max_redirect'] ?? null);
        $this->assertSame(0, Client::$lastRequest['options']['max_redirect'] ?? null);
    }

    /**
     * PG-144: a response body past the ceiling is refused LOUDLY before it
     * reaches the parser — a hostile or proxy-swollen answer must not be
     * handed to json_decode and echoed onward.
     */
    public function testOversizedAsyncBodyIsRefusedLoudly(): void
    {
        Client::$responses['https://api.trakt.tv/users/me'] = [
            'status' => 200,
            'headers' => [],
            'body' => str_repeat('a', HttpClient::MAX_RESPONSE_BYTES + 1),
        ];

        $this->expectException(TraktApiException::class);
        $this->expectExceptionMessageMatches('/Refusing Trakt response body larger than the 4 MiB ceiling \(4194304 bytes\)/');

        (new HttpClient(timeout: 1))->get('https://api.trakt.tv/users/me');
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

    /**
     * M-TRAKT-11 (secaudit #4): the async transport carried Bearer +
     * client_secret over a TLS context that the vendored workerman/http-client
     * pool defaults to verify_peer=false / allow_self_signed=true, while the
     * cURL fallback pinned CURLOPT_SSL_VERIFYPEER + VERIFYHOST=2 + HTTPS-only
     * protocols. Both transports must now enforce verification: requestAsync()
     * passes an explicit strict ssl context (hub 519c0c0 idiom) plus the SNI
     * peer_name taken from the request URL.
     */
    public function testAsyncClientIsConstructedWithStrictTlsContext(): void
    {
        Client::$responses['https://api.trakt.tv/users/me'] = [
            'status' => 200,
            'headers' => [],
            'body' => '{"ok":true}',
        ];

        (new HttpClient(timeout: 1))->get('https://api.trakt.tv/users/me');

        $this->assertNotNull(Client::$lastConfig, 'the async transport must have been used');
        $ssl = Client::$lastConfig['context']['ssl'] ?? null;
        $this->assertIsArray($ssl, 'requestAsync must pass an explicit stream context');
        $this->assertTrue($ssl['verify_peer'], 'peer certificates must be verified (no trust-on-first-use)');
        $this->assertTrue($ssl['verify_peer_name'], 'the certificate hostname must be verified');
        $this->assertFalse($ssl['allow_self_signed'], 'self-signed CAs must be rejected (cURL path rejects them too)');
        $this->assertTrue($ssl['SNI_enabled'], 'SNI must be sent');
        $this->assertSame('api.trakt.tv', $ssl['peer_name'], 'peer_name must pin the SNI/verification hostname');
    }

    /**
     * M-TRAKT-11: the async path must mirror the cURL HTTPS-only protocol pin
     * (CURLOPT_PROTOCOLS) — a non-https URL is a hard error, never cleartext
     * transit of the bearer token.
     */
    public function testAsyncTransportRefusesNonHttpsUrls(): void
    {
        $this->expectException(TraktApiException::class);
        $this->expectExceptionMessageMatches('/HTTPS/');

        (new HttpClient(timeout: 1))->get('http://api.trakt.tv/users/me');
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

    /**
     * TRK-7: the async path used to substitute '' for an unencodable body
     * and send it anyway. It must now throw before any client is built or
     * any request dispatched.
     */
    public function testAsyncUnencodablePostBodyFailsBeforeSend(): void
    {
        Client::$responses['https://api.trakt.tv/sync/history'] = [
            'status' => 200,
            'headers' => [],
            'body' => '{"added":1}',
        ];

        try {
            (new HttpClient(timeout: 1))->post(
                'https://api.trakt.tv/sync/history',
                ['movies' => [['title' => "bad \xB1\x31 utf8"]]]
            );
            $this->fail('Expected TraktApiException for an unencodable body');
        } catch (TraktApiException $e) {
            $this->assertStringStartsWith('Request body could not be JSON-encoded:', $e->getMessage());
        }

        $this->assertNull(Client::$lastRequest, 'nothing may be dispatched');
        $this->assertSame(0, Client::$constructs, 'no client built for a doomed request');
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
                // Accept with a 1s timeout: closing the listening socket (from
                // the test's finally block) wakes accept() to return false on
                // most Swoole builds. The timeout is belt-and-suspenders for
                // builds where that wake-up does not happen — the loop then
                // self-terminates within a second instead of hanging the Co\run
                // container forever.
                $conn = $server->accept(1.0);
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
