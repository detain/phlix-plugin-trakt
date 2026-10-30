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
 * Two binding models are legitimate here:
 * - USER-SCOPED: entries bound to whatever notion of "user" the host already
 *   has (PHP session, JWT subject, DB row keyed by user UUID, …). The default
 *   {@see SessionTraktOAuthStateStore} uses `$_SESSION`, matching the historic
 *   behaviour but lifted out into a swappable seam so the controller becomes
 *   unit-testable and so production deployments that run under Workerman
 *   (where `$_SESSION` is not request-scoped) keep working.
 * - STATE-KEYED SERVER STORE: {@see DbTraktOAuthStateStore} keys rows by the
 *   `state` value alone; the unguessable, single-use `state` acts as the
 *   bearer secret between put() and consume(). It exists for Workerman
 *   deployments without session scope and does NOT bind to a user identity.
 *
 * @since 1.1.0
 */
interface TraktOAuthStateStore
{
    /**
     * Persist a `(state, code_verifier)` pair for later one-shot lookup.
     */
    public function put(string $state, string $codeVerifier): void;

    /**
     * Look up the code_verifier matching the supplied state and atomically
     * remove the entry. Returns null if no matching state exists, which
     * MUST be treated as a CSRF failure by callers.
     */
    public function consume(string $state): ?string;
}
