<?php

declare(strict_types=1);

namespace Workerman\Http;

/**
 * Minimal stub for the Workerman HTTP Response class.
 *
 * Models the surface HttpClient::requestAsync() consumes: the status code, the
 * header map (Retry-After and friends) and the raw body.
 *
 * Header normalization mirrors the real PSR-7 response the Workerman client
 * produces: names are lowercased and every value is wrapped in an array, so
 * the array-value branch of HttpClient::extractRetryAfter() is exercised by
 * the same shape production actually hits.
 */
class Response
{
    /**
     * Normalized headers: lowercase name => list of values.
     *
     * @var array<string, array{0: string}>
     */
    private readonly array $headers;

    /**
     * @param int $statusCode HTTP status code
     * @param array<string, string|int|array<string|int>> $headers Raw header
     *        map (name => value, or name => list of values)
     * @param string $body Raw response body
     */
    public function __construct(
        private readonly int $statusCode,
        array $headers,
        private readonly string $body,
    ) {
        $normalized = [];
        foreach ($headers as $name => $value) {
            $normalized[strtolower((string) $name)] = is_array($value)
                ? array_map(static fn (mixed $v): string => (string) $v, $value)
                : [(string) $value];
        }
        $this->headers = $normalized;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * @return array<string, array{0: string}>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getBody(): string
    {
        return $this->body;
    }
}
