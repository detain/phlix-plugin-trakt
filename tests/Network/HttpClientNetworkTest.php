<?php

declare(strict_types=1);

namespace Phlix\Tests\Network\Plugins\Scrobbler\Trakt;

use Phlix\Plugins\Scrobbler\Trakt\HttpClient;
use Phlix\Plugins\Scrobbler\Trakt\TraktApiException;
use Phlix\Plugins\Scrobbler\Trakt\TraktAuthenticationException;
use PHPUnit\Framework\TestCase;

/**
 * Live round-trip tests for HttpClient against httpbin.org.
 *
 * ⚠ NOT RUN BY DEFAULT. `phpunit.xml` sets defaultTestSuite="Unit" and excludes
 * this directory from it; run these deliberately with:
 *
 *     vendor/bin/phpunit --testsuite Network
 *
 * Why they are quarantined (S250): every assertion here depends on a
 * third-party public service answering correctly. On 2026-08-07 httpbin.org's
 * edge answered `GET /status/422` with a 502, and again with 503 and hard
 * timeouts through the following hours. The client reported the status it
 * actually received - which is correct behaviour - and the suite read that as a
 * defect, turning master red on a schedule nobody controls. Only
 * testParseResponseWithErrorMessage asserts a *specific* status, so it was the
 * only test able to notice; the sibling error tests assert just the exception
 * class, which a 502 also satisfies, and so passed while lying.
 *
 * What the default suite therefore no longer verifies, and what running this
 * file restores: that cURL actually transmits the merged header map and the
 * query string built by get() over the wire, and that a real 2xx/4xx round trip
 * decodes end to end. The status -> exception mapping itself is fully covered
 * deterministically in tests/Unit/HttpClientTest.php.
 */
final class HttpClientNetworkTest extends TestCase
{
    public function testGetWithoutParams(): void
    {
        // Test that get() without params doesn't append ? to URL
        $client = new HttpClient(timeout: 5);

        // Use httpbin.org/get which returns the request info as JSON
        // This tests the GET request path with query building
        $response = $client->get('https://httpbin.org/get');

        $this->assertIsArray($response);
        // httpbin.org returns 'args' key with query params
        $this->assertArrayHasKey('args', $response);
    }

    public function testGetWithParamsBuildsQueryString(): void
    {
        $client = new HttpClient(timeout: 10);

        $response = $client->get('https://httpbin.org/get', [
            'foo' => 'bar',
            'baz' => 'qux',
        ]);

        $this->assertIsArray($response);
        // httpbin.org echoes back the query params in 'args'
        $this->assertSame('bar', $response['args']['foo'] ?? null);
        $this->assertSame('qux', $response['args']['baz'] ?? null);
    }

    public function testPostSendsJsonBody(): void
    {
        $client = new HttpClient(timeout: 10);

        $response = $client->post('https://httpbin.org/post', [
            'test_field' => 'test_value',
            'number' => 42,
        ]);

        $this->assertIsArray($response);
        // httpbin.org echoes back the POST body in 'json'
        $this->assertSame('test_value', $response['json']['test_field'] ?? null);
        $this->assertSame(42, $response['json']['number'] ?? null);
    }

    public function testPostWithHeaders(): void
    {
        $client = new HttpClient(timeout: 10);

        $response = $client->post(
            'https://httpbin.org/post',
            ['data' => 'test'],
            ['X-Custom-Header' => 'custom-value']
        );

        $this->assertIsArray($response);
        // httpbin.org echoes back headers in 'headers'
        $this->assertSame('custom-value', $response['headers']['X-Custom-Header'] ?? null);
    }

    public function test404ResponseThrowsApiException(): void
    {
        $client = new HttpClient(timeout: 10);

        $this->expectException(TraktApiException::class);
        $this->expectExceptionMessage('HTTP 404');

        // httpbin.org/status/404 returns 404
        $client->get('https://httpbin.org/status/404');
    }

    public function test401ResponseThrowsAuthenticationException(): void
    {
        $client = new HttpClient(timeout: 10);

        $this->expectException(TraktAuthenticationException::class);

        // httpbin.org/status/401 returns 401
        $client->get('https://httpbin.org/status/401');
    }

    public function test500ResponseThrowsApiException(): void
    {
        $client = new HttpClient(timeout: 10);

        $this->expectException(TraktApiException::class);
        $this->expectExceptionMessage('HTTP 500');

        // httpbin.org/status/500 returns 500
        $client->get('https://httpbin.org/status/500');
    }

    public function testParseResponseWithErrorMessage(): void
    {
        $client = new HttpClient(timeout: 10);

        $this->expectException(TraktApiException::class);
        $this->expectExceptionMessage('HTTP 422');

        // httpbin.org/status/422 returns JSON error body
        $client->get('https://httpbin.org/status/422');
    }

    public function testGetRequestSendsCorrectHeaders(): void
    {
        $client = new HttpClient(timeout: 10);

        // httpbin.org/headers returns the headers it received
        $response = $client->get('https://httpbin.org/headers');

        $this->assertIsArray($response);
        // Check User-Agent header is sent
        $headers = $response['headers'];
        $this->assertStringContainsString('PhlixMediaServer', $headers['User-Agent'] ?? '');
        $this->assertSame('application/json', $headers['Accept'] ?? '');
        $this->assertSame('application/json', $headers['Content-Type'] ?? '');
    }
}
