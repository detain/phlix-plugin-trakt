<?php

declare(strict_types=1);

namespace Phlix\Plugins\Scrobbler\Trakt;

/**
 * HTTP client implementation for Trakt API requests.
 *
 * @package Phlix\Plugins\Scrobbler\Trakt
 * @since 0.14.0
 */
class HttpClient implements HttpClientInterface
{
    /**
     * @param int $timeout Request timeout in seconds
     */
    public function __construct(
        private readonly int $timeout = 15,
    ) {
    }

    /**
     * @inheritDoc
     */
    public function get(string $url, array $params = [], array $headers = []): array
    {
        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }

        return $this->request('GET', $url, [], $headers);
    }

    /**
     * @inheritDoc
     */
    public function post(string $url, array $data = [], array $headers = []): array
    {
        return $this->request('POST', $url, $data, $headers);
    }

    /**
     * Perform an HTTP request.
     *
     * Non-blocking by default: inside a running Workerman event loop this uses
     * the cooperative-wait {@see \Workerman\Http\Client} pattern (see phlix-server
     * CLAUDE.md "Async Patterns") so the resident worker keeps serving other
     * connections while the Trakt round-trip is in flight. Outside an event loop
     * (unit tests, CLI, single-shot CGI) it falls back to a blocking cURL request
     * — there is no loop to yield to there, so blocking is both safe and required.
     *
     * @param string $method HTTP method
     * @param string $url Full URL
     * @param array<string, mixed> $data Request body
     * @param array<string, string> $headers Additional headers
     *
     * @return array<string, mixed>
     *
     * @throws TraktApiException
     * @throws TraktAuthenticationException
     * @throws TraktRateLimitException
     */
    private function request(string $method, string $url, array $data, array $headers): array
    {
        $requestHeaders = [
            'User-Agent' => 'PhlixMediaServer/1.0',
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];

        foreach ($headers as $key => $value) {
            $requestHeaders[$key] = $value;
        }

        if ($this->eventLoopRunning()) {
            return $this->requestAsync($method, $url, $data, $requestHeaders);
        }

        return $this->requestCurl($method, $url, $data, $requestHeaders);
    }

    /**
     * Whether a Workerman event loop is running so async I/O can yield.
     *
     * The async {@see \Workerman\Http\Client} path only makes sense when the
     * cooperative wait has an event loop to yield back to. Under PHPUnit/CLI
     * neither the client class nor the loop exist, so callers take the blocking
     * cURL fallback instead.
     *
     * @return bool
     */
    private function eventLoopRunning(): bool
    {
        return class_exists(\Workerman\Http\Client::class)
            && class_exists(\Workerman\Worker::class)
            && \Workerman\Worker::$globalEvent !== null;
    }

    /**
     * Non-blocking request via the cooperative-wait Workerman HTTP client.
     *
     * Fires the request on the event loop and then yields (1 ms sleeps, which
     * the Swoole runtime hook turns into cooperative yields) until a success or
     * error callback fires or the timeout elapses — never a hard blocking read.
     *
     * @param string $method HTTP method
     * @param string $url Full URL
     * @param array<string, mixed> $data Request body (JSON-encoded for POST)
     * @param array<string, string> $headers Header map
     *
     * @return array<string, mixed>
     *
     * @throws TraktApiException
     * @throws TraktAuthenticationException
     * @throws TraktRateLimitException
     */
    private function requestAsync(string $method, string $url, array $data, array $headers): array
    {
        $client = new \Workerman\Http\Client(['timeout' => $this->timeout]);

        $state = ['done' => false, 'response' => null, 'error' => null];

        $options = [
            'method' => $method,
            'headers' => $headers,
            'success' => function (mixed $response) use (&$state): void {
                $state['response'] = $response;
                $state['done'] = true;
            },
            'error' => function (mixed $error) use (&$state): void {
                $state['error'] = $error;
                $state['done'] = true;
            },
        ];

        if ($method === 'POST') {
            $encoded = json_encode($data);
            $options['data'] = is_string($encoded) ? $encoded : '';
        }

        $client->request($url, $options);

        // Cooperative wait: yields to the event loop so other tasks proceed.
        $waited = 0.0;
        $maxWait = (float) $this->timeout;
        while (!$state['done'] && $waited < $maxWait) {
            usleep(1000);
            $waited += 0.001;
        }

        if ($state['error'] !== null) {
            $error = $state['error'];
            $message = $error instanceof \Throwable ? $error->getMessage() : (string) $error;
            throw new TraktApiException('HTTP error: ' . $message);
        }

        $response = $state['response'];
        if ($response === null) {
            throw new TraktApiException('HTTP request timed out after ' . $this->timeout . 's');
        }

        /** @var object $response */
        $httpCode = (int) $response->getStatusCode();
        $raw = (string) $response->getBody();

        $responseHeaders = [];
        if (method_exists($response, 'getHeaders')) {
            $responseHeaders = (array) $response->getHeaders();
        }

        return $this->parseResponse($httpCode, $raw, $responseHeaders);
    }

    /**
     * Blocking cURL request — CLI/test/single-shot fallback only.
     *
     * Used when no event loop is running (see {@see self::eventLoopRunning()}),
     * where there is nothing to yield to and a synchronous round-trip is correct.
     *
     * @param string $method HTTP method
     * @param string $url Full URL
     * @param array<string, mixed> $data Request body
     * @param array<string, string> $headers Header map
     *
     * @return array<string, mixed>
     *
     * @throws TraktApiException
     * @throws TraktAuthenticationException
     * @throws TraktRateLimitException
     */
    private function requestCurl(string $method, string $url, array $data, array $headers): array
    {
        $ch = curl_init();

        $requestHeaders = [];
        foreach ($headers as $key => $value) {
            $requestHeaders[] = $key . ': ' . $value;
        }

        // Captured by the CURLOPT_HEADERFUNCTION callback below: one entry per
        // response header line, normalised to an associative name => value map.
        $responseHeaders = [];

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HTTPHEADER => $requestHeaders,
            CURLOPT_HEADERFUNCTION => static function (mixed $ch, string $line) use (&$responseHeaders): int {
                $header = self::parseHeaderLine($line);
                if ($header !== null) {
                    $responseHeaders[$header[0]] = $header[1];
                }

                return strlen($line);
            },
            // TLS verification - explicitly enabled for security
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            // HTTPS-only redirects - prevent redirect to insecure protocols
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            $jsonData = json_encode($data);
            if (is_string($jsonData)) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
            }
        }

        if ($url !== '') {
            curl_setopt($ch, CURLOPT_URL, $url);
        }

        /** @var string|false $raw */
        $raw = curl_exec($ch);
        /** @var int $httpCode */
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        /** @var string $error */
        $error = curl_error($ch);

        if ($raw === false || $error !== '') {
            throw new TraktApiException('cURL error: ' . $error);
        }

        return $this->parseResponse((int) $httpCode, $raw, $responseHeaders);
    }

    /**
     * Map an HTTP status + raw body to a decoded array or the right exception.
     *
     * Shared by the async and cURL transports so both paths raise identical
     * errors (401 → auth, 429 → rate limit with a real Retry-After read,
     * >= 400 → API error) and decode identically.
     *
     * @param int $httpCode HTTP status code
     * @param string $raw Raw response body
     * @param array<string, string|int|array{0: string|int}> $headers Response
     *        headers as an associative name => value map. The cURL transport
     *        normalises to this shape via CURLOPT_HEADERFUNCTION; the async
     *        transport passes through what the client's getHeaders() returns
     *        (repeated headers may be arrays — the first element is used).
     *
     * @return array<string, mixed>
     *
     * @throws TraktApiException
     * @throws TraktAuthenticationException
     * @throws TraktRateLimitException
     */
    private function parseResponse(int $httpCode, string $raw, array $headers = []): array
    {
        if ($httpCode === 401) {
            throw new TraktAuthenticationException('Unauthorized - token invalid or expired');
        }

        if ($httpCode === 429) {
            $decoded = json_decode($raw, true);
            $decoded = is_array($decoded) ? $decoded : [];
            $message = is_string($decoded['error'] ?? null) ? $decoded['error']
                : (is_string($decoded['message'] ?? null) ? $decoded['message'] : 'Rate limit exceeded');
            throw new TraktRateLimitException($message, self::extractRetryAfter($headers));
        }

        if ($httpCode >= 400) {
            $decoded = json_decode($raw, true);
            $decoded = is_array($decoded) ? $decoded : [];
            $message = is_string($decoded['error'] ?? null) ? $decoded['error']
                : (is_string($decoded['message'] ?? null) ? $decoded['message'] : 'HTTP ' . $httpCode);
            throw new TraktApiException($message, $httpCode);
        }

        $decoded = json_decode($raw, true);

        /** @var array<string, mixed> */
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Parse one raw response header line into a name => value pair.
     *
     * Status lines (HTTP/1.1 200 OK) carry no colon and are not headers, so
     * they yield null and the caller skips them.
     *
     * @param string $line A single header line without its trailing CRLF
     *
     * @return array{0: string, 1: string}|null The header name and value, or
     *              null when the line is not a "name: value" header.
     */
    private static function parseHeaderLine(string $line): ?array
    {
        $colonPos = strpos($line, ':');
        if ($colonPos === false) {
            return null;
        }

        return [trim(substr($line, 0, $colonPos)), trim(substr($line, $colonPos + 1))];
    }

    /**
     * Extract the Retry-After header value as an integer number of seconds.
     *
     * Accepts the two shapes headers arrive in: an associative name => value
     * map (the shape the cURL CURLOPT_HEADERFUNCTION callback normalises to),
     * or a map where repeated headers are arrays (the shape Workerman/swoole
     * may produce) — for those the first element is used. Lookup is
     * case-insensitive, per the HTTP header-name rules.
     *
     * Only the delay-seconds form (RFC 9110) is understood: Trakt sends
     * seconds, so an HTTP-date Retry-After value would be non-numeric and map
     * to 0 (retry immediately). Negative values are clamped to 0.
     *
     * @param array<string, string|int|array{0: string|int}> $headers Header map
     *
     * @return int Seconds to wait before retrying; 0 when the header is
     *              missing, non-numeric, or negative.
     */
    private static function extractRetryAfter(array $headers): int
    {
        foreach ($headers as $name => $value) {
            if (strcasecmp((string) $name, 'Retry-After') !== 0) {
                continue;
            }

            if (is_array($value)) {
                $value = $value[0] ?? null;
            }

            if (!is_string($value) && !is_int($value)) {
                return 0;
            }

            return max(0, (int) trim((string) $value));
        }

        return 0;
    }
}
