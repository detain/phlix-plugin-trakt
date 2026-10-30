<?php

declare(strict_types=1);

namespace Workerman\MySQL;

/**
 * Minimal stub for Workerman MySQL Connection class.
 * Only implements the query() method used by TraktHistorySync.
 *
 * For testing, use TestableConnection which extends this class and allows
 * configuring the results returned by query().
 *
 * NOTE (review trakt__srphp C1, 2026-10-29): this stub must mirror the real
 * upstream class — which exposes NO escape_* method. A fabricated
 * real_escapeString() used to live here and kept every Trakt→Phlix pull test
 * green while production fataled on the undefined method. Values travel as
 * bound parameters through query($sql, $params) only; the seam is pinned by
 * tests/Unit/TraktDbSeamTest.php.
 */
class Connection
{
    public function __construct(
        private readonly string $host,
        private readonly string $port,
        private readonly string $user,
        private readonly string $password,
        private readonly string $database,
    ) {
    }

    /**
     * Execute a query and return results.
     *
     * Upstream `Workerman\MySQL\Connection::query()` signals failure with
     * `false` (production callers must keep working against that), so the
     * stub returns `array|false` — the shape the code paths already branch
     * on. The C1 lesson applies: never fabricate or elide seam behavior.
     *
     * @param string $sql The SQL query
     * @param array<mixed> $params Query parameters
     *
     * @return array<array<string, mixed>>|false Array of result rows, or false on failure
     */
    public function query(string $sql, array $params = []): array|false
    {
        // Stub implementation - actual queries not executed in tests
        return [];
    }

    /**
     * Begin a transaction (no-op stub).
     */
    public function beginTrans(): void
    {
    }

    /**
     * Commit the current transaction (no-op stub).
     */
    public function commitTrans(): void
    {
    }

    /**
     * Roll back the current transaction (no-op stub).
     */
    public function rollBackTrans(): void
    {
    }
}

/**
 * Testable connection that extends Connection and allows injecting results.
 */
class TestableConnection extends Connection
{
    /** @var array<array|false> */
    private array $resultsToReturn = [];
    private array $calls = [];

    public function __construct()
    {
        // Call parent with dummy values - they won't be used in tests
        parent::__construct('localhost', '3306', 'user', 'pass', 'test');
    }

    /**
     * Configure the results to return on subsequent query() calls.
     *
     * A queued `false` models a failed statement (upstream contract).
     *
     * @param array<array|false> $results Array of result sets, one per call
     */
    public function setResults(array $results): void
    {
        $this->resultsToReturn = $results;
        $this->calls = [];
    }

    /**
     * @return array<array{sql: string, params: array}>
     */
    public function getCalls(): array
    {
        return $this->calls;
    }

    public function query(string $sql, array $params = []): array|false
    {
        $this->calls[] = ['sql' => $sql, 'params' => $params];

        if (!empty($this->resultsToReturn)) {
            return array_shift($this->resultsToReturn);
        }

        return [];
    }
}
