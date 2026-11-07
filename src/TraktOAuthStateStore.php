<?php

declare(strict_types=1);

namespace Phlix\Plugins\Scrobbler\Trakt;

/**
 * Server-side store for the Trakt OAuth `state` (CSRF) value and the
 * matching PKCE `code_verifier`.
 *
 * The store is one-shot per `state`: after {@see consume()} the entry
 * MUST be deleted so a malicious party cannot replay a captured
 * authorization code through a stolen state value.
 *
 * Entries carry the initiating user's identity when the flow knows it
 * (PG-142): the browser that STARTS the dance is bound to the row, and
 * the callback that FINISHES it must compare the bound identity against
 * the session redeeming the code — refusing on mismatch, and refusing
 * unbound rows whenever a binding was expected. A valid-but-somebody-
 * else's state is exactly the theft this binding forecloses.
 *
 * Two deployment shapes satisfy the same contract:
 * - USER-SCOPED: entries bound to whatever notion of "user" the host already
 *   has (PHP session, JWT subject, DB row keyed by user UUID, …). The default
 *   {@see SessionTraktOAuthStateStore} uses `$_SESSION`, matching the historic
 *   behavior but lifted out into a swappable seam so the controller becomes
 *   unit-testable and so production deployments that run under Workerman
 *   (where `$_SESSION` is not request-scoped) keep working.
 * - STATE-KEYED SERVER STORE: {@see DbTraktOAuthStateStore} keys rows by the
 *   `state` value and stores the identity alongside it; deployments that
 *   cannot start the flow with a known user still write unbound (NULL)
 *   rows, where the unguessable, single-use `state` remains the bearer
 *   secret between put() and consume().
 *
 * @since 1.1.0
 */
interface TraktOAuthStateStore
{
    /**
     * Persist a `(state, code_verifier)` pair for later one-shot lookup,
     * binding the initiating user's identity when the flow knows it.
     *
     * @param string      $state        Unguessable CSRF value
     * @param string      $codeVerifier Matching PKCE code verifier
     * @param string|null $userId       Identity of the user who started the
     *     flow. The empty string parses to "no identity" (null) at this
     *     boundary, so form-shaped callers cannot store a blank binding.
     */
    public function put(string $state, string $codeVerifier, ?string $userId = null): void;

    /**
     * Look up the code_verifier matching the supplied state and atomically
     * remove the entry. Returns null if no matching state exists, which
     * MUST be treated as a CSRF failure by callers.
     *
     * PG-137 law: null is a SECURITY verdict and may only be delivered by
     * a store that actually answered. An implementation whose storage is
     * unavailable (failed statement, lost connection) MUST surface that as
     * a distinct fault — {@see OAuthStateStoreUnavailableException} for
     * the DB-backed store — never as a silent null.
     */
    public function consume(string $state): ?string;

    /**
     * One-shot lookup returning the full entry — the code verifier AND
     * the identity bound at {@see put()} — so the callback can enforce
     * the binding instead of accepting any valid state from any caller
     * (PG-142). The consumption semantics (one-shot wipe, CSRF-null,
     * PG-137 availability law) are identical to {@see consume()}.
     *
     * @return array{code_verifier: string, user_id: ?string}|null
     */
    public function consumeWithIdentity(string $state): ?array;
}
