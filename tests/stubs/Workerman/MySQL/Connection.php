<?php

declare(strict_types=1);

namespace Workerman\MySQL;

/**
 * Minimal stub for Workerman MySQL Connection class.
 * Only implements the query() method used by TraktHistorySync.
 *
 * For testing, use TestableConnection which extends this class and allows
 * configuring the results returned by query().
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
     * @param string $sql The SQL query
     * @param array<mixed> $params Query parameters
     *
     * @return array<array<string, mixed>> Array of result rows
     */
    public function query(string $sql, array $params = []): array
    {
        // Stub implementation - actual queries not executed in tests
        return [];
    }

    /**
     * Escape a string for use in SQL queries.
     *
     * @param string $str String to escape
     *
     * @return string Escaped string
     */
    public function real_escapeString(string $str): string
    {
        // Simple stub: just return the string as-is for testing
        // In production, this properly escapes SQL special characters
        return str_replace(["'", '"', '\\', "\0"], ["''", '\\"', '\\\\', ''], $str);
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
    /** @var array<array> */
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
     * @param array<array> $results Array of result sets, one per call
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

    public function query(string $sql, array $params = []): array
    {
        $this->calls[] = ['sql' => $sql, 'params' => $params];

        if (!empty($this->resultsToReturn)) {
            return array_shift($this->resultsToReturn);
        }

        return [];
    }
}
