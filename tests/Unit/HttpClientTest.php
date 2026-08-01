<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins\Scrobbler\Trakt;

use Phlix\Plugins\Scrobbler\Trakt\HttpClient;
use Phlix\Plugins\Scrobbler\Trakt\TraktApiException;
use Phlix\Plugins\Scrobbler\Trakt\TraktAuthenticationException;
use PHPUnit\Framework\TestCase;

/**
 * Tests for HttpClient.
 *
 * Note: These tests exercise the cURL fallback path since the test environment
 * does not have a running Workerman event loop. The async path (requestAsync)
 * is only exercised in a real Workerman context.
 */
final class HttpClientTest extends TestCase
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

        // Empty URL should cause cURL to fail
        $client->get('');
    }

    public function test404ResponseThrowsApiException(): void
    {
        $client = new HttpClient(timeout: 10);

        $this->expectException(TraktApiException::class);

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
        $this->assertArrayHasKey('headers', $response);

        // Check User-Agent header is sent
        $headers = $response['headers'];
        $this->assertStringContainsString('PhlixMediaServer', $headers['User-Agent'] ?? '');
        $this->assertSame('application/json', $headers['Accept'] ?? '');
        $this->assertSame('application/json', $headers['Content-Type'] ?? '');
    }
}
