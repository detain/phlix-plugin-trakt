<?php

declare(strict_types=1);

namespace Workerman\Http;

/**
 * Minimal stub for the Workerman HTTP Client used by HttpClient::requestAsync().
 *
 * Deterministic mode (default): request() fires the success/error callbacks
 * synchronously from a static response map, so the async branch can be driven
 * without a network or an event loop.
 *
 * Real-IO mode (self::$realIo = true): request() launches a Swoole coroutine
 * that performs a genuine HTTP round trip via Swoole\Coroutine\Http\Client,
 * then fires the callbacks with a Response built from the real wire bytes.
 */
class Client
{
    /**
     * Map of url => response descriptor.
     *
     * @var array<string, array{status: int, headers: array<string, string>, body: string}>
     */
    public static array $responses = [];

    /**
     * Error payload fired by request() when set (deterministic mode).
     *
     * @var mixed
     */
    public static $error = null;

    /**
     * The most recent request() invocation.
     *
     * @var array{url: string, options: array<string, mixed>}|null
     */
    public static ?array $lastRequest = null;

    /**
     * When true, request() performs a real HTTP round trip in a Swoole coroutine.
     */
    public static bool $realIo = false;

    /**
     * When true, request() records the call and fires no callback — the caller
     * observes a timeout.
     */
    public static bool $neverRespond = false;

    /**
     * @param string $url Request URL
     * @param array<string, mixed> $options Client options including the
     *        'success' and 'error' callbacks
     */
    public function request(string $url, array $options): void
    {
        self::$lastRequest = ['url' => $url, 'options' => $options];

        if (self::$neverRespond) {
            return;
        }

        if (self::$realIo) {
            $this->requestRealIo($url, $options);

            return;
        }

        if (self::$error !== null) {
            ($options['error'])(self::$error);

            return;
        }

        if (!isset(self::$responses[$url])) {
            ($options['error'])(new \RuntimeException('No stub response registered for ' . $url));

            return;
        }

        $entry = self::$responses[$url];
        ($options['success'])(new Response($entry['status'], $entry['headers'], $entry['body']));
    }

    /**
     * Genuine HTTP round trip in a Swoole coroutine.
     *
     * @param string $url Request URL
     * @param array<string, mixed> $options Client options with the callbacks
     */
    private function requestRealIo(string $url, array $options): void
    {
        if (!class_exists(\Swoole\Coroutine\Http\Client::class)) {
            ($options['error'])(new \RuntimeException('Swoole coroutine HTTP client unavailable'));

            return;
        }

        \Swoole\Coroutine\go(static function () use ($url, $options): void {
            $parts = parse_url($url);
            $host = is_string($parts['host'] ?? null) ? $parts['host'] : '127.0.0.1';
            $port = (int) ($parts['port'] ?? 80);
            $path = (string) ($parts['path'] ?? '/');
            if (!empty($parts['query'])) {
                $path .= '?' . $parts['query'];
            }

            $client = new \Swoole\Coroutine\Http\Client($host, $port);
            $client->set(['timeout' => 5]);

            $method = is_string($options['method'] ?? null) ? $options['method'] : 'GET';
            if ($method === 'POST') {
                $data = is_string($options['data'] ?? null) ? $options['data'] : '';
                $client->post($path, $data);
            } else {
                $client->get($path);
            }

            if ($client->statusCode === 0) {
                $message = is_string($client->errMsg) && $client->errMsg !== ''
                    ? $client->errMsg
                    : 'Connection failed';
                ($options['error'])(new \RuntimeException($message));
                $client->close();

                return;
            }

            // Pass the wire headers through untouched — Response normalizes
            // them (lowercased names, array values) exactly like the real
            // PSR-7 class, so the suite exercises the production shape.
            $response = new Response(
                (int) $client->getStatusCode(),
                (array) $client->headers,
                (string) $client->getBody(),
            );
            ($options['success'])($response);
            $client->close();
        });
    }

    /**
     * Reset all static state between tests.
     */
    public static function reset(): void
    {
        self::$responses = [];
        self::$error = null;
        self::$lastRequest = null;
        self::$realIo = false;
        self::$neverRespond = false;
    }
}
