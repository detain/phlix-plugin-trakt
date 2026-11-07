<?php

declare(strict_types=1);

namespace Phlix\Plugins\Scrobbler\Trakt;

/**
 * Per-user settings for the Trakt scrobbler plugin.
 *
 * Stores OAuth tokens, sync preferences, and user identity.
 * Settings are serialized to JSON and stored in the plugins.settings_json
 * column, loaded via Plugin::configure() on enable.
 *
 * @package Phlix\Plugins\Scrobbler\Trakt
 * @since 1.1.0
 */
final class TraktSettings
{
    /**
     * Proactive refresh buffer: refresh token when it expires within this many seconds.
     *
     * Trakt tokens typically expire in 3 months, but we refresh proactively to avoid
     * edge-case failures. 5 minutes gives enough headroom for the HTTP round-trip
     * to complete before the token actually expires.
     */
    private const TOKEN_REFRESH_BUFFER_SECONDS = 300;

    /**
     * @param string|null $accessToken OAuth access token (null when not authenticated)
     * @param string|null $refreshToken OAuth refresh token (null when not authenticated)
     * @param int|null $expiresAt Unix timestamp when access token expires (null when not set)
     * @param bool $syncEnabled Whether two-way history sync is enabled
     * @param int $syncIntervalMinutes How often to run Trakt→Phlix sync (in minutes)
     * @param bool $scrobbleEnabled Whether scrobbling on playback events is enabled
     * @param string $username Trakt username for attribution
     */
    public function __construct(
        public readonly ?string $accessToken = null,
        public readonly ?string $refreshToken = null,
        public readonly ?int $expiresAt = null,
        public readonly bool $syncEnabled = true,
        public readonly int $syncIntervalMinutes = 30,
        public readonly bool $scrobbleEnabled = true,
        public readonly string $username = '',
    ) {
    }

    /**
     * Create settings from an array (as loaded from DB settings JSON).
     *
     * When a {@see TokenCipher} is supplied, the stored `access_token` /
     * `refresh_token` values are decrypted on the way in (they are written
     * encrypted by {@see TraktSettings::toStorageArray()}). When no cipher is
     * supplied — e.g. host has no encryption key, or a caller passing plaintext
     * test data — the token fields are taken verbatim. The cipher's `decrypt()`
     * also passes through any value it does not recognise as its own ciphertext,
     * so legacy plaintext tokens written before encryption was enabled still
     * load correctly.
     *
     * @param array<string, mixed> $data Key-value array from settings_json
     * @param TokenCipher|null $cipher Optional decryptor for the token fields
     *
     * @return self
     *
     * @since 1.1.0
     */
    public static function fromArray(array $data, ?TokenCipher $cipher = null): self
    {
        $accessToken = is_string($data['access_token'] ?? null) ? $data['access_token'] : null;
        $refreshToken = is_string($data['refresh_token'] ?? null) ? $data['refresh_token'] : null;

        if ($cipher !== null) {
            $accessToken = $accessToken !== null ? $cipher->decrypt($accessToken) : null;
            $refreshToken = $refreshToken !== null ? $cipher->decrypt($refreshToken) : null;
        }

        return new self(
            accessToken: $accessToken,
            refreshToken: $refreshToken,
            expiresAt: is_int($data['expires_at'] ?? null) ? $data['expires_at'] : null,
            syncEnabled: is_bool($data['sync_enabled'] ?? null) ? $data['sync_enabled'] : true,
            syncIntervalMinutes: is_int($data['sync_interval_minutes'] ?? null) ? $data['sync_interval_minutes'] : 30,
            scrobbleEnabled: is_bool($data['scrobble_enabled'] ?? null) ? $data['scrobble_enabled'] : true,
            username: is_string($data['username'] ?? null) ? $data['username'] : '',
        );
    }

    /**
     * Convert settings to the in-memory array (raw token values, no encryption).
     *
     * This is the plaintext representation used internally (e.g. re-applying
     * settings through {@see TraktPlugin::configure()}). It MUST NOT be written
     * verbatim to the database — use {@see TraktSettings::toStorageArray()} for
     * the at-rest payload — and MUST NOT be returned to the admin SPA — use
     * {@see TraktSettings::toSpaArray()} for that.
     *
     * @return array<string, mixed>
     *
     * @since 1.1.0
     */
    public function toArray(): array
    {
        return [
            'access_token' => $this->accessToken,
            'refresh_token' => $this->refreshToken,
            'expires_at' => $this->expiresAt,
            'sync_enabled' => $this->syncEnabled,
            'sync_interval_minutes' => $this->syncIntervalMinutes,
            'scrobble_enabled' => $this->scrobbleEnabled,
            'username' => $this->username,
        ];
    }

    /**
     * Convert settings to the AT-REST storage array (tokens encrypted).
     *
     * Identical to {@see TraktSettings::toArray()} except the `access_token`
     * and `refresh_token` fields are encrypted with the supplied
     * {@see TokenCipher} so the raw, long-lived OAuth credentials never land in
     * `plugins.settings_json` as plaintext.
     *
     * Graceful degrade: when no cipher is supplied (host has no encryption key)
     * the tokens are stored as-is. Callers should log a warning in that case.
     *
     * @param TokenCipher|null $cipher Optional encryptor for the token fields
     *
     * @return array<string, mixed>
     *
     * @since 1.1.0
     */
    public function toStorageArray(?TokenCipher $cipher = null): array
    {
        $data = $this->toArray();

        if ($cipher === null) {
            return $data;
        }

        if ($this->accessToken !== null) {
            $data['access_token'] = $cipher->encrypt($this->accessToken);
        }
        if ($this->refreshToken !== null) {
            $data['refresh_token'] = $cipher->encrypt($this->refreshToken);
        }

        return $data;
    }

    /**
     * Convert settings to a REDACTED projection safe to return to the admin SPA.
     *
     * The raw OAuth token strings are NEVER included; instead a `has_tokens`
     * boolean signals whether the account is connected. Only the
     * user-facing/editable preferences and non-secret status fields are exposed.
     *
     * `token_encrypted` (PG-143) advertises whether the stored tokens sit
     * encrypted at rest. The settings object cannot know the deployment's
     * cipher posture on its own, so the default is the conservative false
     * and {@see TraktPlugin::getSettingsForSpa()} passes the truth.
     *
     * @return array<string, mixed>
     *
     * @since 1.1.0
     */
    public function toSpaArray(bool $tokensEncrypted = false): array
    {
        return [
            'username' => $this->username,
            'sync_enabled' => $this->syncEnabled,
            'sync_interval_minutes' => $this->syncIntervalMinutes,
            'scrobble_enabled' => $this->scrobbleEnabled,
            // Status only — never the raw tokens.
            'has_tokens' => $this->hasTokens(),
            'token_expires_at' => $this->expiresAt,
            'token_encrypted' => $tokensEncrypted,
        ];
    }

    /**
     * Whether the OAuth tokens are present and potentially valid.
     *
     * Note: This does not validate token expiration. Use isTokenExpired()
     * for that check.
     *
     * M-TRAKT-16 (srphp m8): "present" means a USABLE token — a coerced
     * empty string (what a fieldless 2xx used to produce at the API
     * boundary) is not custody of a token. Callers gate re-auth on this,
     * so it must not report true for '' values.
     *
     * @return bool True when both access and refresh tokens are present and non-empty
     *
     * @since 1.1.0
     */
    public function hasTokens(): bool
    {
        return is_string($this->accessToken) && $this->accessToken !== ''
            && is_string($this->refreshToken) && $this->refreshToken !== '';
    }

    /**
     * Whether the access token has expired or is about to expire.
     *
     * Uses a proactive refresh buffer ({@see TOKEN_REFRESH_BUFFER_SECONDS}) to
     * refresh the token before it actually expires, preventing edge-case failures
     * where the token expires mid-request.
     *
     * @return bool True when expired or no token exists
     *
     * @since 1.1.0
     */
    public function isTokenExpired(): bool
    {
        if ($this->expiresAt === null) {
            return $this->accessToken === null;
        }

        // Proactive refresh: consider token "expired" when it will expire within
        // the buffer window, so we refresh before the actual expiry.
        return time() >= ($this->expiresAt - self::TOKEN_REFRESH_BUFFER_SECONDS);
    }

    /**
     * Whether the plugin is fully configured and ready to scrobble.
     *
     * @return bool True when hasTokens() and username is non-empty
     *
     * @since 1.1.0
     */
    public function isConfigured(): bool
    {
        return $this->hasTokens() && $this->username !== '';
    }

    /**
     * Copy-from-base: rebuild this value object with the given field overrides.
     *
     * PG-131 (review trakt__srphp #7): every credential mutation used to
     * hand-repeat all seven constructor fields at its own call site — four
     * copies whose "keep the rest" list silently rots the day an eighth
     * field is added (a dropped line resets one preference to its default
     * on the next save). One spread of the current values is the only
     * correct copy, and it can never forget a new field.
     *
     * @param array{accessToken?: string|null, refreshToken?: string|null,
     *              expiresAt?: int|null, syncEnabled?: bool,
     *              syncIntervalMinutes?: int, scrobbleEnabled?: bool,
     *              username?: string} $overrides Constructor-parameter name
     *              => new value
     */
    private function copyWith(array $overrides): self
    {
        $current = [
            'accessToken' => $this->accessToken,
            'refreshToken' => $this->refreshToken,
            'expiresAt' => $this->expiresAt,
            'syncEnabled' => $this->syncEnabled,
            'syncIntervalMinutes' => $this->syncIntervalMinutes,
            'scrobbleEnabled' => $this->scrobbleEnabled,
            'username' => $this->username,
        ];

        // String-keyed spread == named arguments: every field arrives by
        // name, so the merge can never silently reorder a value into the
        // wrong parameter.
        return new self(...array_merge($current, $overrides));
    }

    /**
     * Same settings on a different access token (credential mutation only).
     *
     * @param string $accessToken New OAuth access token
     *
     * @return self A new instance; this object is never mutated.
     */
    public function withAccessToken(string $accessToken): self
    {
        return $this->copyWith(['accessToken' => $accessToken]);
    }

    /**
     * Same settings on a different refresh token (credential mutation only).
     *
     * @param string $refreshToken New OAuth refresh token
     *
     * @return self A new instance; this object is never mutated.
     */
    public function withRefreshToken(string $refreshToken): self
    {
        return $this->copyWith(['refreshToken' => $refreshToken]);
    }

    /**
     * Same settings on a different token triplet.
     *
     * The token fields move as ONE unit — access, refresh and expiry are a
     * single custody state, and mixing a rotated access token with a stale
     * expiry would fabricate a token that reads as fresh forever. Passing
     * nulls is the UNCONNECTED projection: it expresses "tokens dropped,
     * preferences kept" (the decrypt-failure custody fallback) without
     * rebuilding six fields at the call site.
     *
     * @param string|null $accessToken New access token, or null to drop it
     * @param string|null $refreshToken New refresh token, or null to drop it
     * @param int|null $expiresAt New expiry, or null to drop it
     *
     * @return self A new instance; this object is never mutated.
     */
    public function withTokenPair(?string $accessToken, ?string $refreshToken, ?int $expiresAt): self
    {
        return $this->copyWith([
            'accessToken' => $accessToken,
            'refreshToken' => $refreshToken,
            'expiresAt' => $expiresAt,
        ]);
    }
}
