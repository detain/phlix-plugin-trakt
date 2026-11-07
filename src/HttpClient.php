<?php

declare(strict_types=1);

namespace Phlix\Plugins\Scrobbler\Trakt;

use Phlix\Common\Http\EventLoopTls;
use Phlix\Common\Runtime\WorkerContext;

/**
 * HTTP client implementation for Trakt API requests.
 *
 * @package Phlix\Plugins\Scrobbler\Trakt
 * @since 1.1.0
 */
class HttpClient implements HttpClientInterface
{
    /**
     * Pooled async HTTP clients, keyed by TLS peer host.
     *
     * PG-125: the client used to be constructed per request (paying the pool
     * setup each time) while now being reused — one instance per distinct
     * peer host, because the strict TLS context pins `peer_name` at
     * construction, so an instance can only ever serve its own peer.
     * Production speaks to a single host (api.trakt.tv, OAuth exchanges
     * included); the suite exercises the per-peer keying with a second one.
     *
     * @var array<string, \Workerman\Http\Client>
     */
    private array $asyncClients = [];

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
     * Transport choice follows the host's law (phlix-server
     * src/Hub/HttpClient.php, src/Server/Integrations/Trakt/HttpClient.php):
     * the cooperative-wait async {@see \Workerman\Http\Client} path is valid
     * ONLY when a Workerman event loop is running AND the caller executes
     * inside a coroutine AND the URL is not https-under-the-Swoole-loop
     * (where async TLS reads stall). Everywhere else — unit tests, CLI, and
     * the plugin's own non-coroutine Timer callbacks — a blocking cURL
     * request is both safe and required: there is nothing to yield to, so a
     * spinning async wait could only ever false-timeout (review trakt srphp
     * #1, PG-125).
     *
     * @param string $method HTTP method
     * @param string $url Full URL
     * @param array<string, mixed> $data Request body
     * @param array<string, string> $headers Additional headers
     *
     * @return array<mixed> Decoded JSON body — object endpoints yield
     *             string keys, list endpoints integer keys; callers narrow per
     *             endpoint.
     *
     * @throws TraktApiException
     * @throws TraktAuthenticationException
     * @throws TraktRateLimitException
     */
    private function request(string $method, string $url, array $data, array $headers): array
    {
        $requestHeaders = [
            'User-Agent' => 'phlix/trakt (+https://github.com/detain/phlix-plugin-trakt)',
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];

        foreach ($headers as $key => $value) {
            $requestHeaders[$key] = $value;
        }

        if ($this->asyncTransportUsable($url)) {
            return $this->requestAsync($method, $url, $data, $requestHeaders);
        }

        return $this->requestCurl($method, $url, $data, $requestHeaders);
    }

    /**
     * Whether the cooperative-wait async transport can actually complete here.
     *
     * PG-125 (review trakt__srphp #1 [Critical]): the old gate asked only
     * whether {@see \Workerman\Worker}::$globalEvent existed — but that
     * marker is set on every live server worker and says nothing about
     * cooperativity. Every scrobble and pull this plugin makes runs from a
     * Workerman Timer callback that owns the worker thread; while such a
     * callback spins waiting for socket callbacks, no socket callback can
     * fire, so every production round trip died at its deadline with a false
     * timeout while a perfectly good cURL fallback sat unreachable.
     *
     * The gate now mirrors the host's: async only inside a coroutine with a
     * running loop (WorkerContext), and never for https while the Swoole
     * event loop drives the process (EventLoopTls — client-side TLS reads
     * stall there; cURL's own blocking transport is unaffected). The host
     * helpers are authoritative when the plugin runs inside the server
     * process; when they are absent (standalone CLI, older host) there is no
     * trustworthy coroutine context, so the safe default is the blocking
     * transport — a slow request beats a permanently false-timed-out one.
     */
    private function asyncTransportUsable(string $url): bool
    {
        if (!class_exists(\Workerman\Http\Client::class)) {
            return false;
        }

        if (!class_exists(WorkerContext::class) || !class_exists(EventLoopTls::class)) {
            return false;
        }

        if (EventLoopTls::requiresBlockingCurl($url)) {
            return false;
        }

        return WorkerContext::isEventLoopRunning() && WorkerContext::inCoroutine();
    }

    /**
     * Reuse one async client per TLS peer host (lazy, PG-125).
     *
     * The strict TLS context carries a pinned `peer_name`, so the pool key is
     * the host: every request to the same peer reuses the same client (and
     * its connection pool) instead of paying per-request construction, while
     * a request to a different peer gets its own client and still verifies
     * against its own name. Production serves the single api.trakt.tv peer;
     * the suite exercises the second-peer path.
     *
     * @param string $host TLS peer host taken from the request URL
     */
    private function asyncClient(string $host): \Workerman\Http\Client
    {
        if (!isset($this->asyncClients[$host])) {
            $this->asyncClients[$host] = new \Workerman\Http\Client([
                'timeout' => $this->timeout,
                'context' => [
                    'ssl' => [
                        'verify_peer' => true,
                        'verify_peer_name' => true,
                        'allow_self_signed' => false,
                        'SNI_enabled' => true,
                        'peer_name' => $host,
                    ],
                ],
            ]);
        }

        return $this->asyncClients[$host];
    }

    /**
     * Non-blocking request via the cooperative-wait Workerman HTTP client.
     *
     * Only reachable through {@see self::asyncTransportUsable()} — i.e. from
     * a coroutine inside a running event loop, where the wait below yields
     * (1 ms sleeps, which the Swoole runtime hook turns into cooperative
     * yields) and socket callbacks can genuinely fire. The deadline is driven
     * by wall-clock time, never by counting sleep iterations (PG-125:
     * `waited += 0.001` under-counted once a yield took longer than the
     * nominal 1 ms, so the reported timeout lied).
     *
     * @param string $method HTTP method
     * @param string $url Full URL
     * @param array<string, mixed> $data Request body (JSON-encoded for POST)
     * @param array<string, string> $headers Header map
     *
     * @return array<mixed> Decoded JSON body — object endpoints yield
     *             string keys, list endpoints integer keys; callers narrow per
     *             endpoint.
     *
     * @throws TraktApiException
     * @throws TraktAuthenticationException
     * @throws TraktRateLimitException
     */
    private function requestAsync(string $method, string $url, array $data, array $headers): array
    {
        // M-TRAKT-11 (secaudit #4): mirror the cURL transport's TLS pinning on
        // the async path. The vendored workerman/http-client pool opens TLS
        // UNVERIFIED by default (ConnectionPool::create ships
        // verify_peer=false / verify_peer_name=false / allow_self_signed=true
        // and its shallow array_merge REPLACES the whole 'ssl' section), so an
        // explicit strict context must ride the client options — including
        // peer_name, which the pool only auto-derives on its own defaults path
        // that our replacement clobbers. The cURL pins (CURLOPT_SSL_VERIFYPEER,
        // CURLOPT_SSL_VERIFYHOST=2, HTTPS-only CURLOPT_PROTOCOLS) are the
        // reference; the scheme guard below mirrors its HTTPS-only law.
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = parse_url($url, PHP_URL_HOST);
        if ($scheme !== 'https' || !is_string($host) || $host === '') {
            throw new TraktApiException('Refusing non-HTTPS async request to ' . $url);
        }

        $client = $this->asyncClient($host);

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

        // Cooperative wait against a wall-clock deadline: yields to the event
        // loop so other tasks proceed, and measures real elapsed time rather
        // than tallying sleep iterations (PG-125).
        $deadline = microtime(true) + (float) $this->timeout;
        while (!$state['done'] && microtime(true) < $deadline) {
            usleep(1000);
        }

        if ($state['error'] !== null) {
            $error = $state['error'];
            if ($error instanceof \Throwable) {
                $message = $error->getMessage();
            } elseif (is_scalar($error)) {
                $message = (string) $error;
            } else {
                // Non-throwable, non-scalar error payloads (arrays, plain objects)
                // used to fatal on the string cast; report their type instead.
                $message = gettype($error);
            }
            throw new TraktApiException('HTTP error: ' . $message);
        }

        $response = $state['response'];
        if ($response === null) {
            throw new TraktApiException('HTTP request timed out after ' . $this->timeout . 's');
        }

        // PG-138 (review trakt__srphp m15): the `@var object` assertion was
        // the live site behind the phpstan object:: wildcard silencer — the
        // response contract was verified nowhere and every method on it was
        // silently legal. Narrow by structure and fail loud when the
        // transport hands back anything that cannot answer the two calls
        // this method depends on. getHeaders stays duck-typed on purpose:
        // it is optional to the parser (empty map is a valid answer).
        if (
            !is_object($response)
            || !method_exists($response, 'getStatusCode')
            || !method_exists($response, 'getBody')
        ) {
            throw new TraktApiException(
                'HTTP transport returned an unexpected response payload: ' . get_debug_type($response)
            );
        }

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
     * Used when the async transport is unusable (see
     * {@see self::asyncTransportUsable()}): no coroutine inside a running
     * loop to yield to, or https under the Swoole loop where async TLS
     * reads stall. There a synchronous round-trip is the correct choice.
     *
     * @param string $method HTTP method
     * @param string $url Full URL
     * @param array<string, mixed> $data Request body
     * @param array<string, string> $headers Header map
     *
     * @return array<mixed> Decoded JSON body — object endpoints yield
     *             string keys, list endpoints integer keys; callers narrow per
     *             endpoint.
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
     * 3xx → API error, >= 400 → API error) and decode identically. Only
     * 2xx is a success.
     *
     * @param int $httpCode HTTP status code
     * @param string $raw Raw response body
     * @param array<string, string|int|array{0: string|int}> $headers Response
     *        headers as an associative name => value map. The cURL transport
     *        normalises to this shape via CURLOPT_HEADERFUNCTION; the async
     *        transport passes through what the client's getHeaders() returns
     *        (repeated headers may be arrays — the first element is used).
     *
     * @return array<mixed> Decoded JSON body — object endpoints yield
     *             string keys, list endpoints integer keys; callers narrow per
     *             endpoint.
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
            // The hint is surfaced for hosts; the plugin itself has no
            // auto-retry consumer — deliberate law, see README "Rate Limits
            // and Scheduling" (M-TRAKT-13/19).
            throw new TraktRateLimitException($message, self::extractRetryAfter($headers));
        }

        if ($httpCode >= 300 && $httpCode < 400) {
            // Neither transport follows redirects (no CURLOPT_FOLLOWLOCATION
            // on the cURL path), so a 3xx arriving here means the API — or a
            // middlebox posing as it — answered a JSON call with a redirect.
            // A redirect body is not the expected payload: decoding it as a
            // successful "empty result" let callers treat failures as
            // success-with-no-data. Fail loud instead. (Review trakt__srphp m9.)
            throw new TraktApiException('Unexpected HTTP redirect (' . $httpCode . ')', $httpCode);
        }

        if ($httpCode >= 400) {
            $decoded = json_decode($raw, true);
            $decoded = is_array($decoded) ? $decoded : [];
            $message = is_string($decoded['error'] ?? null) ? $decoded['error']
                : (is_string($decoded['message'] ?? null) ? $decoded['message'] : 'HTTP ' . $httpCode);
            throw new TraktApiException($message, $httpCode);
        }

        $decoded = json_decode($raw, true);

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
