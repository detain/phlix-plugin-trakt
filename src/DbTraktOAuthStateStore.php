<?php

/**
 * Phlix media server component: Trakt.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Plugins\Scrobbler\Trakt;

use Phlix\Common\Uuid;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Workerman\MySQL\Connection;

/**
 * DB-backed implementation of {@see TraktOAuthStateStore}.
 *
 * Stores Trakt OAuth PKCE state entries in a database table instead of $_SESSION,
 * which causes state leakage between Workerman workers/requests.
 *
 * Uses the unified `oauth_state_store` table with provider = 'trakt',
 * the `user_id` column carrying the identity bound at {@see put()}
 * (PG-142 — NULL for an unbound, state-keyed deployment), and data JSON
 * containing {code_verifier}.
 *
 * Each entry has a 10-minute TTL to prevent stale state accumulation.
 * Expired entries are cleaned up on each {@see consume()} call.
 *
 * @since 1.5.0
 */
final class DbTraktOAuthStateStore implements TraktOAuthStateStore
{
    /**
     * Provider identifier for Trakt in the unified oauth_state_store table.
     */
    private const string PROVIDER = 'trakt';

    /**
     * Default TTL for state entries in seconds (10 minutes).
     */
    private const int TTL_SECONDS = 600;

    /**
     * Number of expired entries to delete per cleanup batch.
     */
    private const int CLEANUP_BATCH_SIZE = 100;

    private Connection $db;
    private int $ttlSeconds;
    private LoggerInterface $logger;

    /**
     * Create a new DbTraktOAuthStateStore instance.
     *
     * @param Connection $db Workerman MySQL connection instance
     * @param int        $ttlSeconds Optional TTL in seconds (default: 600 = 10 minutes)
     * @param LoggerInterface|null $logger Optional PSR-3 logger for loud cleanup failures
     */
    public function __construct(Connection $db, int $ttlSeconds = self::TTL_SECONDS, ?LoggerInterface $logger = null)
    {
        $this->db = $db;
        $this->ttlSeconds = $ttlSeconds > 0 ? $ttlSeconds : self::TTL_SECONDS;
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Persist a PKCE code verifier keyed by the state value, binding the
     * initiating user's identity into the row when the flow knows it.
     *
     * @param string      $state        Unique state identifier
     * @param string      $codeVerifier PKCE code verifier
     * @param string|null $userId       Bound identity; '' parses to null
     *                                  (no binding), never a blank one
     *
     * @throws \RuntimeException If the payload cannot be encoded (PG-137) or
     *                           the database insert fails
     */
    public function put(string $state, string $codeVerifier, ?string $userId = null): void
    {
        $id = Uuid::v4();
        $expiresAt = time() + $this->ttlSeconds;
        $data = json_encode(['code_verifier' => $codeVerifier]);
        $boundUserId = ($userId === '') ? null : $userId;

        // PG-137 (review trakt__srphp m14): the encode used to be unchecked.
        // An unencodable verifier (invalid UTF-8 — this value rides in from
        // the OAuth dance) bound as an empty column: the row INSERTed fine,
        // and consume() answered "never issued" forever — a dead link behind
        // a green write path. Reject BEFORE touching the table.
        if ($data === false) {
            throw new \RuntimeException(
                'Failed to encode Trakt OAuth state payload: ' . json_last_error_msg()
            );
        }

        $result = $this->db->query(
            "INSERT INTO oauth_state_store (id, provider, state_value, user_id, data, expires_at)
             VALUES (?, ?, ?, ?, ?, FROM_UNIXTIME(?))",
            [$id, self::PROVIDER, $state, $boundUserId, $data, $expiresAt]
        );

        if ($result === false) {
            throw new \RuntimeException('Failed to persist Trakt OAuth state to database');
        }
    }

    /**
     * One-shot lookup and deletion of the entry for a given state.
     *
     * Returns null if the state was never issued, has already been consumed,
     * or has expired (TTL exceeded) — the CSRF-law verdict, delivered only
     * when the store ANSWERED. Availability faults throw instead (PG-137):
     * a failed statement or connection may not read back to the caller as
     * a replay rejection.
     *
     * Cleans up expired entries in batches to prevent table bloat.
     *
     * @param string $state State value to consume
     *
     * @return string|null The code verifier if found and consumed, null otherwise
     *
     * @throws OAuthStateStoreUnavailableException When the store could not
     *     be consulted at all (PG-137)
     */
    public function consume(string $state): ?string
    {
        $entry = $this->consumeWithIdentity($state);

        return $entry === null ? null : $entry['code_verifier'];
    }

    /**
     * One-shot lookup returning the full entry — code verifier and the
     * identity bound at {@see put()} — so the callback can enforce the
     * binding (PG-142) instead of accepting any valid state from any
     * caller. Consumption law is identical to {@see consume()}.
     *
     * @return array{code_verifier: string, user_id: ?string}|null
     *
     * @throws OAuthStateStoreUnavailableException When the store could not
     *     be consulted at all (PG-137)
     */
    public function consumeWithIdentity(string $state): ?array
    {
        // Atomic consume: row-locked SELECT (FOR UPDATE) within the
        // transaction, then DELETE — a second concurrent callback on the
        // same state blocks on the lock and reads nothing after commit.
        $entry = $this->fetchAndDelete($state);
        $this->cleanupExpiredEntries();

        return $entry;
    }

    /**
     * Fetch the entry and delete it atomically within a transaction.
     *
     * PG-137 (review trakt__srphp m14) split the three outcomes the parent
     * blended into one `null`: a genuine miss (answerable CSRF-null), a
     * FAILED statement (`query()` false — the upstream contract), and a
     * THROWN connection fault (previously swallowed by a catch that also
     * rolled back unguarded). The latter two are availability faults and
     * surface as {@see OAuthStateStoreUnavailableException}; only a store
     * that ANSWERED may deliver the security verdict.
     *
     * @param string $state State value to look up
     *
     * @return array{code_verifier: string, user_id: ?string}|null The entry
     *                     when consumed, null for a genuine miss or an
     *                     unusable payload
     *
     * @throws OAuthStateStoreUnavailableException On any infrastructure
     *                                             failure (statement false,
     *                                             connection throw)
     */
    private function fetchAndDelete(string $state): ?array
    {
        $this->db->beginTrans();
        try {
            $result = $this->db->query(
                "SELECT data, user_id FROM oauth_state_store WHERE provider = ? AND state_value = ?"
                . " AND expires_at > NOW() FOR UPDATE",
                [self::PROVIDER, $state]
            );

            if ($result === false) {
                // PG-137: `false` is the statement FAILING, not the table
                // answering "no rows" (that is an empty array). A failed
                // lookup may not borrow the CSRF verdict's null.
                throw new \RuntimeException('SELECT FOR UPDATE of the OAuth state failed');
            }

            if (!isset($result[0]) || !is_array($result[0])) {
                // Genuine miss — the CSRF-law null, unchanged by PG-137.
                $this->safeRollback();
                return null;
            }

            /** @var array<string, string> $row */
            $row = $result[0];

            $deleteResult = $this->db->query(
                "DELETE FROM oauth_state_store WHERE provider = ? AND state_value = ?",
                [self::PROVIDER, $state]
            );

            if ($deleteResult === false) {
                // PG-137: the row exists but its one-shot DELETE failed —
                // whether the state survives is UNKNOWN, which no security
                // verdict can honestly cover.
                throw new \RuntimeException('one-shot DELETE of the OAuth state failed');
            }

            $this->db->commitTrans();

            // Post-commit payload verdicts: the state WAS consumed here, so
            // an unusable payload is a settled decline (null), not an
            // availability fault. Untouched by PG-137.
            $data = is_string($row['data'] ?? null) ? json_decode($row['data'], true) : null;
            if (!is_array($data)) {
                return null;
            }

            $codeVerifier = is_string($data['code_verifier'] ?? null) ? $data['code_verifier'] : '';

            if ($codeVerifier === '') {
                return null;
            }

            // The binding rides in the column: NULL is the honest "unbound"
            // verdict the callback must weigh (PG-142).
            $userId = is_string($row['user_id'] ?? null) ? $row['user_id'] : null;

            return ['code_verifier' => $codeVerifier, 'user_id' => $userId];
        } catch (\Throwable $e) {
            // PG-137: every fault that reaches here (failed statements,
            // throwing connections, beginTrans) is an availability fault.
            // It gets a loud, typed face — never the security null — and
            // the rollback is guarded so a dead connection's rollback
            // exception can neither escape nor mask the report (the parent
            // rolled back UNGUARDED inside this catch).
            $this->safeRollback();
            $this->logger->error(
                'Trakt OAuth state store unavailable during consume; the state could not be adjudicated',
                ['error' => $e->getMessage()],
            );

            throw new OAuthStateStoreUnavailableException(
                'Trakt OAuth state store unavailable during consume: ' . $e->getMessage(),
                previous: $e,
            );
        }
    }

    /**
     * Roll the consume transaction back without letting a failed rollback
     * escape the handler (PG-137 — on a dead connection rollBackTrans()
     * itself throws; that exception used to leak out of the catch block
     * and out of consume()).
     */
    private function safeRollback(): void
    {
        try {
            $this->db->rollBackTrans();
        } catch (\Throwable $e) {
            $this->logger->warning('Trakt OAuth state store rollback failed; the consume transaction may dangle', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Delete expired TRAKT entries in batches to prevent table bloat.
     *
     * Called after each {@see consume()} to lazily clean up stale entries.
     * This approach avoids adding overhead to every put() call.
     *
     * The sweep is provider-scoped: the oauth_state_store table is SHARED
     * across every OAuth-flavoured plugin/host flow (OIDC, GitHub, WebAuthn
     * all keep rows here), and a trakt callback has no business deleting
     * other providers' state rows — cross-plugin write amplification is a
     * separation violation of the unified-table contract. A failed sweep is
     * logged loudly rather than silently regrowing the table; it never
     * changes the consume() verdict, which is already decided.
     */
    private function cleanupExpiredEntries(): void
    {
        try {
            $result = $this->db->query(
                "DELETE FROM oauth_state_store WHERE provider = ? AND expires_at <= NOW() LIMIT ?",
                [self::PROVIDER, self::CLEANUP_BATCH_SIZE]
            );

            if ($result === false) {
                $this->logger->warning('Trakt OAuth state cleanup DELETE failed; expired trakt rows may accumulate');
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Trakt OAuth state cleanup raised; expired trakt rows may accumulate', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
