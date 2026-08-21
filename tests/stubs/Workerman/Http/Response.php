<?php

declare(strict_types=1);

namespace Workerman\Http;

/**
 * Minimal stub for the Workerman HTTP Response class.
 *
 * Models the surface HttpClient::requestAsync() consumes: the status code, the
 * header map (Retry-After and friends) and the raw body.
 */
class Response
{
    public function __construct(
        private readonly int $statusCode,
        private readonly array $headers,
        private readonly string $body,
    ) {
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * @return array<string, string|int|array{0: string|int}>
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
