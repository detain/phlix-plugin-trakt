<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins\Scrobbler\Trakt;

use Phlix\Plugins\Scrobbler\Trakt\HttpClient;
use Phlix\Plugins\Scrobbler\Trakt\RemoteText;
use Phlix\Plugins\Scrobbler\Trakt\TraktApiException;
use Phlix\Plugins\Scrobbler\Trakt\TraktAuthenticationException;
use Phlix\Plugins\Scrobbler\Trakt\TraktRateLimitException;
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
 * Note: the cURL fallback path is under test here. The async path
 * (requestAsync) — the branch the resident worker actually uses — has its own
 * coverage in HttpClientAsyncTest.php, which drives it through Workerman
 * stubs in deterministic mode and through real Swoole round trips for the
 * wire-verified cases.
 */
final class HttpClientTest extends TestCase
{
    /**
     * Invoke the private status -> result mapping directly.
     *
     * @param int $httpCode HTTP status code
     * @param string $raw Raw response body
     * @param array<string, string|int|array{0: string|int}> $headers Response headers
     *
     * @return array<string, mixed>
     */
    private function parseResponse(int $httpCode, string $raw, array $headers = []): array
    {
        $client = new HttpClient(timeout: 1);
        $method = new \ReflectionMethod($client, 'parseResponse');
        $method->setAccessible(true);

        /** @var array<string, mixed> $result */
        $result = $method->invoke($client, $httpCode, $raw, $headers);

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
            $this->assertSame('Trakt rejected the access token (invalid or expired)', $e->getMessage());
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

    // --- 429 rate limit branch ----------------------------------------------

    /**
     * A 429 must throw TraktRateLimitException with the body's error message
     * (error key, then message key, then the 'Rate limit exceeded' default).
     */
    public function test429WithBodyErrorMessageThrowsRateLimitException(): void
    {
        try {
            $this->parseResponse(429, '{"error":"too many requests"}');
            $this->fail('Expected TraktRateLimitException');
        } catch (TraktRateLimitException $e) {
            $this->assertSame('too many requests', $e->getMessage());
            $this->assertSame(429, $e->getCode());
            $this->assertSame(0, $e->retryAfter, 'no Retry-After header means 0 seconds');
        }
    }

    /**
     * Header lookup must be case-insensitive: a lowercase `retry-after` name
     * (the shape Swoole/Workerman normalise to) still yields the value.
     */
    public function test429ReadsCaseInsensitiveRetryAfterHeader(): void
    {
        try {
            $this->parseResponse(429, '{"error":"rate limited"}', ['retry-after' => '30']);
            $this->fail('Expected TraktRateLimitException');
        } catch (TraktRateLimitException $e) {
            $this->assertSame(30, $e->retryAfter);
        }
    }

    public function test429WithoutRetryAfterHeaderDefaultsToZero(): void
    {
        try {
            $this->parseResponse(429, '{"error":"rate limited"}', ['X-Rate-Limit' => '42']);
            $this->fail('Expected TraktRateLimitException');
        } catch (TraktRateLimitException $e) {
            $this->assertSame(0, $e->retryAfter);
        }
    }

    /**
     * Control: only 429 becomes a rate-limit failure. A 400/500 must remain a
     * plain TraktApiException, or the dedicated branch would swallow errors.
     */
    public function testNon429StatusesAreNotRateLimitExceptions(): void
    {
        foreach ([400, 500] as $status) {
            try {
                $this->parseResponse($status, '');
                $this->fail('Expected TraktApiException for HTTP ' . $status);
            } catch (TraktApiException $e) {
                $this->assertNotInstanceOf(TraktRateLimitException::class, $e);
                $this->assertSame('HTTP ' . $status, $e->getMessage());
            }
        }
    }

    // --- header-line parsing (cURL HEADERFUNCTION) -------------------------

    public function testParseHeaderLineParsesNameValuePair(): void
    {
        $client = new HttpClient(timeout: 1);
        $method = new \ReflectionMethod($client, 'parseHeaderLine');
        $method->setAccessible(true);

        /** @var array{0: string, 1: string}|null $pair */
        $pair = $method->invoke($client, 'Retry-After: 42');

        $this->assertIsArray($pair);
        $this->assertSame('Retry-After', $pair[0]);
        $this->assertSame('42', $pair[1]);
    }

    public function testParseHeaderLineSkipsStatusLine(): void
    {
        $client = new HttpClient(timeout: 1);
        $method = new \ReflectionMethod($client, 'parseHeaderLine');
        $method->setAccessible(true);

        $this->assertNull($method->invoke($client, 'HTTP/1.1 200 OK'));
    }

    public function testParseHeaderLineSkipsMalformedLine(): void
    {
        $client = new HttpClient(timeout: 1);
        $method = new \ReflectionMethod($client, 'parseHeaderLine');
        $method->setAccessible(true);

        $this->assertNull($method->invoke($client, 'no-colon-here'));
    }

    // --- Retry-After extraction (both header shapes + clamping) -------------

    public function testExtractRetryAfterReadsArrayValuedHeader(): void
    {
        // The PSR-7 shape the Workerman async transport produces: lowercased
        // name, value wrapped in an array. This pins the array branch of
        // extractRetryAfter() directly, independent of stub behaviour.
        $client = new HttpClient(timeout: 1);
        $method = new \ReflectionMethod($client, 'extractRetryAfter');
        $method->setAccessible(true);

        $this->assertSame(30, $method->invoke($client, ['retry-after' => ['30']]));
    }

    public function testExtractRetryAfterClampsNegativeValuesToZero(): void
    {
        $client = new HttpClient(timeout: 1);
        $method = new \ReflectionMethod($client, 'extractRetryAfter');
        $method->setAccessible(true);

        $this->assertSame(0, $method->invoke($client, ['Retry-After' => '-5']));
    }

    // --- success path ------------------------------------------------------

    public function testSuccessfulResponseIsDecoded(): void
    {
        $this->assertSame(['id' => 7, 'title' => 'Arrival'], $this->parseResponse(200, '{"id":7,"title":"Arrival"}'));
        $this->assertSame(['ok' => true], $this->parseResponse(201, '{"ok":true}'));
    }

    /**
     * Status-class law (review trakt__srphp m9, W-min181): only 2xx is a
     * success. 3xx raises an API error (neither transport follows redirects,
     * so a redirect is never the expected JSON payload), >= 400 raises the
     * mapped errors. The 299/300/400 triple keeps any drift of the two
     * comparison thresholds detectable from both sides.
     */
    public function testRedirectsRaiseAndOnlyTwoHundredDecodes(): void
    {
        $this->assertSame(['ok' => 1], $this->parseResponse(299, '{"ok":1}'));

        foreach ([300, 302, 399] as $status) {
            try {
                $this->parseResponse($status, '{"redirected":true}');
                $this->fail('HTTP ' . $status . ' must raise TraktApiException');
            } catch (TraktApiException $e) {
                $this->assertSame($status, $e->getCode());
            }
        }

        $this->expectException(TraktApiException::class);
        $this->parseResponse(400, '{"error":"bad"}');
    }

    /**
     * TRK-6 body law: a non-empty 2xx body that is not JSON at all is a
     * failed call, not "success with no data". The old pin blessed
     * `200 + garbage => []`, which let a truncated / HTML / middlebox body
     * read as an empty history page or an empty push result — the sync
     * loops then recorded success. It now raises TraktApiException carrying
     * the status code, so every caller's existing TraktApiException arm
     * records the failure (TraktHistorySync logs and returns 0/false).
     *
     * The remote body is never echoed into the message (it may be anything,
     * including a token-endpoint payload); only the decoder's reason is.
     */
    public function testUndecodableNonEmptySuccessBodyRaises(): void
    {
        $cases = [[200, 'not json at all'], [201, '<html>proxy page</html>'], [200, '{"truncated":']];

        foreach ($cases as [$status, $body]) {
            try {
                $this->parseResponse($status, $body);
                $this->fail('HTTP ' . $status . ' with an undecodable body must raise TraktApiException');
            } catch (TraktAuthenticationException | TraktRateLimitException $e) {
                $this->fail('Undecodable 2xx body must map to the plain API error, got ' . $e::class);
            } catch (TraktApiException $e) {
                $this->assertSame($status, $e->getCode());
                $this->assertStringContainsString('Undecodable', $e->getMessage());
                $this->assertStringNotContainsString($body, $e->getMessage());
            }
        }
    }

    /**
     * The empty-body leg of the success path is unchanged: 204 No Content
     * (and an empty / whitespace-only 2xx body) carries no payload by
     * design and still yields []. Well-formed JSON that is not an
     * object/list (a bare scalar) is decodable, so it keeps the narrowing
     * to [] — only the undecodable case fails loud.
     */
    public function testEmptySuccessBodyAndBareScalarYieldEmptyArray(): void
    {
        $this->assertSame([], $this->parseResponse(204, ''));
        $this->assertSame([], $this->parseResponse(200, ''));
        $this->assertSame([], $this->parseResponse(200, " \r\n"));
        $this->assertSame([], $this->parseResponse(200, '"a bare string"'));
        $this->assertSame([], $this->parseResponse(200, 'null'));
    }

    // --- transport ---------------------------------------------------------

    public function testAsyncTransportRefusedInPlainTestEnvironment(): void
    {
        $client = new HttpClient();

        // Use reflection to test the private gate. PG-125 renamed
        // eventLoopRunning() to asyncTransportUsable() and moved the
        // cooperativity question from a bare loop marker to the host
        // coroutine-context helpers.
        $method = (new \ReflectionClass($client))->getMethod('asyncTransportUsable');
        $method->setAccessible(true);

        // In the PHPUnit/test environment WorkerContext reports no running
        // loop and no coroutine, so the async transport is refused.
        $this->assertFalse($method->invoke($client, 'https://api.trakt.tv/users/me'));
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

    /**
     * TRK-7: an unencodable payload (invalid UTF-8) used to go out as a
     * bodiless POST, so the failure surfaced as whatever the remote said. It
     * must now fail locally, before the handle is built: against the dead
     * port a sent request would report 'cURL error:' instead, so the
     * encoder message proves nothing left the process.
     */
    public function testUnencodablePostBodyFailsBeforeCurlSends(): void
    {
        $client = new HttpClient(timeout: 5);

        try {
            $client->post('https://127.0.0.1:1/unreachable', ['title' => "bad \xB1\x31 utf8"]);
            $this->fail('Expected TraktApiException for an unencodable body');
        } catch (TraktApiException $e) {
            $this->assertStringStartsWith('Request body could not be JSON-encoded:', $e->getMessage());
            $this->assertStringNotContainsString('cURL error', $e->getMessage());
            $this->assertStringNotContainsString('bad ', $e->getMessage(), 'payload must not be echoed');
        }
    }

    // --- PG-144: remote text bounded, response body capped ------------------

    public function testLongRemoteErrorTextIsBoundedIntoApiError(): void
    {
        $longError = str_repeat('x', 5_000);

        try {
            $this->parseResponse(400, json_encode(['error' => $longError]));
            $this->fail('Expected TraktApiException');
        } catch (TraktApiException $e) {
            $this->assertLessThanOrEqual(RemoteText::MAX_MESSAGE_CHARS + 32, strlen($e->getMessage()));
            $this->assertStringStartsWith('xxx', $e->getMessage());
            $this->assertStringNotContainsString($longError, $e->getMessage());
        }
    }

    public function testLongRemoteRateLimitTextIsBounded(): void
    {
        try {
            $this->parseResponse(429, json_encode(['error' => str_repeat('y', 5_000)]));
            $this->fail('Expected TraktRateLimitException');
        } catch (TraktRateLimitException $e) {
            $this->assertLessThanOrEqual(RemoteText::MAX_MESSAGE_CHARS + 32, strlen($e->getMessage()));
        }
    }

    public function testMultiByteRemoteTextIsBoundedWithoutBreakingCodepoints(): void
    {
        try {
            $this->parseResponse(400, json_encode(['error' => str_repeat('é', 5_000)]));
            $this->fail('Expected TraktApiException');
        } catch (TraktApiException $e) {
            $this->assertTrue(
                mb_check_encoding($e->getMessage(), 'UTF-8'),
                'bounded text must not end in a broken codepoint',
            );
            $this->assertLessThanOrEqual(RemoteText::MAX_MESSAGE_CHARS + 32, mb_strlen($e->getMessage(), 'UTF-8'));
        }
    }

    public function testRemoteTextAtTheExactCapPassesThroughVerbatim(): void
    {
        $text = str_repeat('z', RemoteText::MAX_MESSAGE_CHARS);

        try {
            $this->parseResponse(400, json_encode(['error' => $text]));
            $this->fail('Expected TraktApiException');
        } catch (TraktApiException $e) {
            $this->assertSame($text, $e->getMessage());
        }
    }

    public function testBodyWithinCeilingPredicateHoldsAtTheBoundary(): void
    {
        $predicate = new \ReflectionMethod(HttpClient::class, 'bodyWithinCeiling');
        $predicate->setAccessible(true);

        $cap = HttpClient::MAX_RESPONSE_BYTES;
        $this->assertTrue($predicate->invoke(null, 0, $cap), 'a body of exactly the ceiling is within it');
        $this->assertTrue($predicate->invoke(null, $cap - 1, 1));
        $this->assertFalse($predicate->invoke(null, $cap - 1, 2), 'one byte past the ceiling must fail the predicate');
    }

    public function testBodySinkAbortsTransferWhenCeilingIsBreached(): void
    {
        $sink = new \ReflectionMethod(HttpClient::class, 'curlBodySink');
        $sink->setAccessible(true);

        /** @var array{buffered: int, overflow: bool, body: string} $state */
        $state = ['buffered' => 0, 'overflow' => false, 'body' => ''];
        $chunk = str_repeat('a', 1024);

        $accepted = $sink->invokeArgs(null, [&$state, $chunk]);
        $this->assertSame(1024, $accepted, 'a chunk under the ceiling must be accepted whole');
        $this->assertFalse($state['overflow']);
        $this->assertSame(1024, $state['buffered']);
        $this->assertSame($chunk, $state['body']);

        $breach = $sink->invokeArgs(null, [&$state, str_repeat('a', HttpClient::MAX_RESPONSE_BYTES)]);
        $this->assertSame(0, $breach, 'the sink must return 0 (abort) once the ceiling would be breached');
        $this->assertTrue($state['overflow']);
        $this->assertSame(1024, $state['buffered'], 'an overflowing chunk must never be counted');
        $this->assertSame($chunk, $state['body'], 'an overflowing chunk must never be buffered');
    }
}
