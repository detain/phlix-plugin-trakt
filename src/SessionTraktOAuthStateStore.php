<?php

declare(strict_types=1);

namespace Phlix\Plugins\Scrobbler\Trakt;

/**
 * `$_SESSION`-backed implementation of {@see TraktOAuthStateStore}.
 *
 * Retains the historic per-session storage behavior of the Trakt
 * OAuth flow while honoring the one-shot contract: {@see consume()}
 * unsets the entry before returning it so a captured state value
 * cannot be replayed. Since PG-142 the entry also carries the bound
 * user identity; the one-shot wipe covers all three session keys so
 * no read shape can resurrect a consumed entry.
 *
 * @since 1.1.0
 */
final class SessionTraktOAuthStateStore implements TraktOAuthStateStore
{
    private const STATE_KEY = 'trakt_oauth_state';
    private const VERIFIER_KEY = 'trakt_oauth_code_verifier';
    private const USER_ID_KEY = 'trakt_oauth_user_id';

    /**
     * TRK-15 attestation: the verifier sits in plaintext in the session.
     * Accepted by design; compensating controls are the session's own
     * lifetime/TTL, one-shot consume (entry wiped on first read) and the
     * constant-time hash_equals() state comparison.
     */
    public function put(string $state, string $codeVerifier, ?string $userId = null): void
    {
        $_SESSION[self::STATE_KEY] = $state;
        $_SESSION[self::VERIFIER_KEY] = $codeVerifier;
        // Parse-at-boundary: '' is "no identity", the same verdict as null.
        $_SESSION[self::USER_ID_KEY] = ($userId === '') ? null : $userId;
    }

    public function consume(string $state): ?string
    {
        $entry = $this->consumeWithIdentity($state);

        return $entry === null ? null : $entry['code_verifier'];
    }

    /**
     * @return array{code_verifier: string, user_id: ?string}|null
     */
    public function consumeWithIdentity(string $state): ?array
    {
        $saved = is_string($_SESSION[self::STATE_KEY] ?? null) ? $_SESSION[self::STATE_KEY] : '';
        $verifier = is_string($_SESSION[self::VERIFIER_KEY] ?? null) ? $_SESSION[self::VERIFIER_KEY] : '';
        $userId = is_string($_SESSION[self::USER_ID_KEY] ?? null) ? $_SESSION[self::USER_ID_KEY] : null;

        // One-shot: regardless of outcome we wipe the saved values so a
        // replay attempt cannot reuse them — identity included.
        unset($_SESSION[self::STATE_KEY], $_SESSION[self::VERIFIER_KEY], $_SESSION[self::USER_ID_KEY]);

        if ($saved === '' || $verifier === '') {
            return null;
        }
        if (!hash_equals($saved, $state)) {
            return null;
        }

        return ['code_verifier' => $verifier, 'user_id' => $userId];
    }
}
