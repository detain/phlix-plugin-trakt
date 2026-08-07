<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins\Scrobbler\Trakt;

use Phlix\Plugins\Scrobbler\Trakt\HttpClient;
use Phlix\Plugins\Scrobbler\Trakt\TraktApiException;
use Phlix\Plugins\Scrobbler\Trakt\TraktAuthenticationException;
use PHPUnit\Framework\TestCase;

/**
 * Deterministic tests for HttpClient.
 *
 * These tests make NO network requests. The status -> exception mapping is
 * exercised through the private `parseResponse()` seam, and the cURL transport
 * through error paths that resolve locally (no URL, a non-HTTPS URL, a closed
 * local port).
 *
 * The previous version of this file asked httpbin.org to produce each status
 * over the public internet, which made the mapping assertions hostage to a
 * third party: when httpbin's edge answered 502 instead of the requested 422,
 * the client reported 'HTTP 502' - correctly - and the test read that correct
 * behaviour as a failure. The live round-trip tests now live in
 * tests/Network/HttpClientNetworkTest.php and are excluded from the default
 * suite; see that file's header for what they do and do not cover.
 *
 * Note: the cURL fallback path is the one under test because the test
 * environment has no running Workerman event loop. The async path
 * (requestAsync) is only exercised in a real Workerman context.
 */
final class HttpClientTest extends TestCase
{
    /**
     * Invoke the private status -> result mapping directly.
     *
     * @param int $httpCode HTTP status code
     * @param string $raw Raw response body
     *
     * @return array<string, mixed>
     */
    private function parseResponse(int $httpCode, string $raw): array
    {
        $client = new HttpClient(timeout: 1);
        $method = new \ReflectionMethod($client, 'parseResponse');
        $method->setAccessible(true);

        /** @var array<string, mixed> $result */
        $result = $method->invoke($client, $httpCode, $raw);

        return $result;
    }

    // --- status -> exception mapping ---------------------------------------

    /**
     * The status the server actually returned must be the status reported.
     *
     * This is the assertion S250 exists to protect: a scrobbler that renames a
     * 502 as a 422 (or vice versa) hides an API contract failure from the
     * operator. Several statuses are checked so that a hardcoded literal in
     * place of `'HTTP ' . $httpCode` cannot satisfy the whole set.
     *
     * @dataProvider errorStatusProvider
     */
    public function testErrorStatusIsReportedVerbatimInMessageAndCode(int $status): void
    {
        try {
            $this->parseResponse($status, '');
            $this->fail('Expected TraktApiException for HTTP ' . $status);
        } catch (TraktApiException $e) {
            $this->assertSame('HTTP ' . $status, $e->getMessage());
            $this->assertSame($status, $e->getCode());
        }
    }

    /**
     * @return array<string, array{int}>
     */
    public static function errorStatusProvider(): array
    {
        return [
            '400 bad request' => [400],
            '404 not found' => [404],
            '409 conflict' => [409],
            '422 unprocessable' => [422],
            '429 rate limited' => [429],
            '500 server error' => [500],
            '502 bad gateway' => [502],
            '503 unavailable' => [503],
        ];
    }

    public function testUnauthorizedMapsToAuthenticationException(): void
    {
        try {
            $this->parseResponse(401, '');
            $this->fail('Expected TraktAuthenticationException for HTTP 401');
        } catch (TraktApiException $e) {
            $this->assertInstanceOf(TraktAuthenticationException::class, $e);
            $this->assertSame('Unauthorized - token invalid or expired', $e->getMessage());
        }
    }

    /**
     * Control for the `=== 401` test above: the statuses either side of it must
     * NOT become authentication failures, or widening that comparison would go
     * unnoticed. TraktAuthenticationException extends TraktApiException, so the
     * negative has to be asserted explicitly.
     *
     * @dataProvider nonAuthStatusProvider
     */
    public function testNeighbouringStatusesAreNotAuthenticationFailures(int $status): void
    {
        try {
            $this->parseResponse($status, '');
            $this->fail('Expected TraktApiException for HTTP ' . $status);
        } catch (TraktApiException $e) {
            $this->assertNotInstanceOf(TraktAuthenticationException::class, $e);
            $this->assertSame('HTTP ' . $status, $e->getMessage());
        }
    }

    /**
     * @return array<string, array{int}>
     */
    public static function nonAuthStatusProvider(): array
    {
        return [
            '400 is not auth' => [400],
            '402 is not auth' => [402],
            '403 is not auth' => [403],
        ];
    }

    // --- error body precedence ---------------------------------------------

    public function testErrorKeyInBodyReplacesGenericStatusMessage(): void
    {
        try {
            $this->parseResponse(422, '{"error":"invalid item"}');
            $this->fail('Expected TraktApiException');
        } catch (TraktApiException $e) {
            $this->assertSame('invalid item', $e->getMessage());
            $this->assertSame(422, $e->getCode());
        }
    }

    public function testMessageKeyUsedWhenErrorKeyIsAbsent(): void
    {
        try {
            $this->parseResponse(400, '{"message":"missing ids"}');
            $this->fail('Expected TraktApiException');
        } catch (TraktApiException $e) {
            $this->assertSame('missing ids', $e->getMessage());
            $this->assertSame(400, $e->getCode());
        }
    }

    public function testErrorKeyWinsOverMessageKey(): void
    {
        try {
            $this->parseResponse(400, '{"error":"from error","message":"from message"}');
            $this->fail('Expected TraktApiException');
        } catch (TraktApiException $e) {
            $this->assertSame('from error', $e->getMessage());
        }
    }

    /**
     * A non-string `error` must fall back to the status, not be cast to one.
     */
    public function testNonStringErrorKeyFallsBackToTheStatusMessage(): void
    {
        try {
            $this->parseResponse(502, '{"error":42}');
            $this->fail('Expected TraktApiException');
        } catch (TraktApiException $e) {
            $this->assertSame('HTTP 502', $e->getMessage());
            $this->assertSame(502, $e->getCode());
        }
    }

    public function testNonJsonErrorBodyFallsBackToTheStatusMessage(): void
    {
        try {
            $this->parseResponse(500, '<html>gateway down</html>');
            $this->fail('Expected TraktApiException');
        } catch (TraktApiException $e) {
            $this->assertSame('HTTP 500', $e->getMessage());
        }
    }

    // --- success path ------------------------------------------------------

    public function testSuccessfulResponseIsDecoded(): void
    {
        $this->assertSame(['id' => 7, 'title' => 'Arrival'], $this->parseResponse(200, '{"id":7,"title":"Arrival"}'));
        $this->assertSame(['ok' => true], $this->parseResponse(201, '{"ok":true}'));
    }

    /**
     * Control for the `>= 400` threshold: a 3xx must still decode rather than
     * throw, so narrowing or widening that comparison is detectable.
     */
    public function testSubFourHundredStatusDoesNotThrow(): void
    {
        $this->assertSame(['redirected' => true], $this->parseResponse(399, '{"redirected":true}'));
        $this->assertSame(['ok' => 1], $this->parseResponse(302, '{"ok":1}'));
    }

    public function testNonArrayOrUndecodableSuccessBodyYieldsEmptyArray(): void
    {
        $this->assertSame([], $this->parseResponse(200, 'not json at all'));
        $this->assertSame([], $this->parseResponse(200, '"a bare string"'));
        $this->assertSame([], $this->parseResponse(204, ''));
    }

    // --- transport ---------------------------------------------------------

    public function testEventLoopRunningReturnsFalseInTestEnvironment(): void
    {
        $client = new HttpClient();

        // Use reflection to test the private method
        $reflection = new \ReflectionClass($client);
        $method = $reflection->getMethod('eventLoopRunning');
        $method->setAccessible(true);

        // In PHPUnit/test environment, no Workerman event loop is running
        $result = $method->invoke($client);
        $this->assertFalse($result);
    }

    public function testInvalidUrlThrowsException(): void
    {
        $client = new HttpClient(timeout: 5);

        $this->expectException(TraktApiException::class);
        $this->expectExceptionMessage('cURL error:');

        // Empty URL is never handed to cURL, so curl_exec fails with "No URL set"
        $client->get('');
    }

    /**
     * CURLOPT_PROTOCOLS is pinned to HTTPS; a plaintext URL must be refused by
     * cURL itself rather than silently transmitted.
     */
    public function testPlaintextHttpUrlIsRefusedByCurl(): void
    {
        $client = new HttpClient(timeout: 5);

        try {
            $client->get('http://127.0.0.1:1/never-sent');
            $this->fail('Expected a plaintext URL to be refused');
        } catch (TraktApiException $e) {
            $this->assertStringContainsString('cURL error:', $e->getMessage());
            $this->assertStringContainsStringIgnoringCase('protocol', $e->getMessage());
        }
    }

    public function testTransportFailureSurfacesAsApiException(): void
    {
        $client = new HttpClient(timeout: 5);

        $this->expectException(TraktApiException::class);
        $this->expectExceptionMessage('cURL error:');

        // Nothing listens on port 1; the connection fails without leaving the host.
        $client->get('https://127.0.0.1:1/unreachable', ['foo' => 'bar']);
    }

    public function testPostTransportFailureSurfacesAsApiException(): void
    {
        $client = new HttpClient(timeout: 5);

        $this->expectException(TraktApiException::class);
        $this->expectExceptionMessage('cURL error:');

        $client->post(
            'https://127.0.0.1:1/unreachable',
            ['test_field' => 'test_value'],
            ['X-Custom-Header' => 'custom-value']
        );
    }
}
