<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins\Scrobbler\Trakt;

use Phlix\Plugins\Scrobbler\Trakt\SodiumTokenCipher;
use Phlix\Plugins\Scrobbler\Trakt\TokenCipher;
use Phlix\Plugins\Scrobbler\Trakt\TraktSettings;
use PHPUnit\Framework\TestCase;

final class TraktSettingsTest extends TestCase
{
    public function testConstructorStoresAllValues(): void
    {
        $settings = new TraktSettings(
            accessToken: 'test-access-token',
            refreshToken: 'test-refresh-token',
            expiresAt: 1699999999,
            syncEnabled: true,
            syncIntervalMinutes: 60,
            scrobbleEnabled: false,
            username: 'testuser'
        );

        $this->assertSame('test-access-token', $settings->accessToken);
        $this->assertSame('test-refresh-token', $settings->refreshToken);
        $this->assertSame(1699999999, $settings->expiresAt);
        $this->assertTrue($settings->syncEnabled);
        $this->assertSame(60, $settings->syncIntervalMinutes);
        $this->assertFalse($settings->scrobbleEnabled);
        $this->assertSame('testuser', $settings->username);
    }

    public function testDefaultValues(): void
    {
        $settings = new TraktSettings();

        $this->assertNull($settings->accessToken);
        $this->assertNull($settings->refreshToken);
        $this->assertNull($settings->expiresAt);
        $this->assertTrue($settings->syncEnabled);
        $this->assertSame(30, $settings->syncIntervalMinutes);
        $this->assertTrue($settings->scrobbleEnabled);
        $this->assertSame('', $settings->username);
    }

    public function testFromArray(): void
    {
        $data = [
            'access_token' => 'array-access',
            'refresh_token' => 'array-refresh',
            'expires_at' => 1700000000,
            'sync_enabled' => false,
            'sync_interval_minutes' => 45,
            'scrobble_enabled' => false,
            'username' => 'arrayuser',
        ];

        $settings = TraktSettings::fromArray($data);

        $this->assertSame('array-access', $settings->accessToken);
        $this->assertSame('array-refresh', $settings->refreshToken);
        $this->assertSame(1700000000, $settings->expiresAt);
        $this->assertFalse($settings->syncEnabled);
        $this->assertSame(45, $settings->syncIntervalMinutes);
        $this->assertFalse($settings->scrobbleEnabled);
        $this->assertSame('arrayuser', $settings->username);
    }

    public function testToArray(): void
    {
        $settings = new TraktSettings(
            accessToken: 'toarray-access',
            refreshToken: 'toarray-refresh',
            expiresAt: 1700000000,
            syncEnabled: true,
            syncIntervalMinutes: 30,
            scrobbleEnabled: true,
            username: 'toarrayuser'
        );

        $array = $settings->toArray();

        $this->assertSame('toarray-access', $array['access_token']);
        $this->assertSame('toarray-refresh', $array['refresh_token']);
        $this->assertSame(1700000000, $array['expires_at']);
        $this->assertTrue($array['sync_enabled']);
        $this->assertSame(30, $array['sync_interval_minutes']);
        $this->assertTrue($array['scrobble_enabled']);
        $this->assertSame('toarrayuser', $array['username']);
    }

    public function testHasTokens(): void
    {
        $settingsWithTokens = new TraktSettings(
            accessToken: 'access',
            refreshToken: 'refresh'
        );
        $settingsWithoutAccess = new TraktSettings(
            accessToken: null,
            refreshToken: 'refresh'
        );
        $settingsWithoutRefresh = new TraktSettings(
            accessToken: 'access',
            refreshToken: null
        );
        $settingsWithoutTokens = new TraktSettings();

        $this->assertTrue($settingsWithTokens->hasTokens());
        $this->assertFalse($settingsWithoutAccess->hasTokens());
        $this->assertFalse($settingsWithoutRefresh->hasTokens());
        $this->assertFalse($settingsWithoutTokens->hasTokens());
    }

    public function testIsConfigured(): void
    {
        $configured = new TraktSettings(
            accessToken: 'access',
            refreshToken: 'refresh',
            username: 'testuser'
        );
        $noUsername = new TraktSettings(
            accessToken: 'access',
            refreshToken: 'refresh',
            username: ''
        );
        $noTokens = new TraktSettings(username: 'testuser');

        $this->assertTrue($configured->isConfigured());
        $this->assertFalse($noUsername->isConfigured());
        $this->assertFalse($noTokens->isConfigured());
    }

    public function testIsTokenExpiredWhenNoToken(): void
    {
        $settings = new TraktSettings();
        $this->assertTrue($settings->isTokenExpired());
    }

    public function testIsTokenExpiredWhenExpired(): void
    {
        $settings = new TraktSettings(
            accessToken: 'test',
            expiresAt: time() - 3600
        );
        $this->assertTrue($settings->isTokenExpired());
    }

    public function testIsTokenValidWhenNotExpired(): void
    {
        $settings = new TraktSettings(
            accessToken: 'test',
            expiresAt: time() + 3600
        );
        $this->assertFalse($settings->isTokenExpired());
    }

    // --- S1: encryption at rest + SPA redaction -----------------------------

    /**
     * toStorageArray() with a cipher must NOT contain the raw token strings;
     * fromArray() with the same cipher must round-trip back to the originals.
     */
    public function testStorageArrayEncryptsTokensAndRoundTrips(): void
    {
        $cipher = new FakeReversibleCipher();
        $settings = new TraktSettings(
            accessToken: 'plain-access-token',
            refreshToken: 'plain-refresh-token',
            expiresAt: 1700000000,
            username: 'alice',
        );

        $stored = $settings->toStorageArray($cipher);

        // The persisted payload carries ciphertext, NOT the raw tokens.
        $this->assertNotSame('plain-access-token', $stored['access_token']);
        $this->assertNotSame('plain-refresh-token', $stored['refresh_token']);
        $this->assertStringNotContainsString('plain-access-token', (string) $stored['access_token']);
        $this->assertStringNotContainsString('plain-refresh-token', (string) $stored['refresh_token']);

        // Round-trip: decrypting on load yields the originals.
        $restored = TraktSettings::fromArray($stored, $cipher);
        $this->assertSame('plain-access-token', $restored->accessToken);
        $this->assertSame('plain-refresh-token', $restored->refreshToken);
        $this->assertSame(1700000000, $restored->expiresAt);
        $this->assertSame('alice', $restored->username);
    }

    /**
     * The libsodium-backed cipher genuinely encrypts (ciphertext differs each
     * call due to the random nonce) and decrypts back to the plaintext.
     */
    public function testSodiumCipherRoundTripsThroughStorage(): void
    {
        $cipher = new SodiumTokenCipher(str_repeat("\x11", SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
        $settings = new TraktSettings(
            accessToken: 'super-secret-access',
            refreshToken: 'super-secret-refresh',
        );

        $stored = $settings->toStorageArray($cipher);

        $this->assertStringStartsWith('v1:', (string) $stored['access_token']);
        $this->assertStringNotContainsString('super-secret-access', (string) $stored['access_token']);

        // Two encryptions of the same plaintext differ (random nonce).
        $again = $settings->toStorageArray($cipher);
        $this->assertNotSame($stored['access_token'], $again['access_token']);

        $restored = TraktSettings::fromArray($stored, $cipher);
        $this->assertSame('super-secret-access', $restored->accessToken);
        $this->assertSame('super-secret-refresh', $restored->refreshToken);
    }

    /**
     * Graceful degrade: with no cipher, toStorageArray() returns plaintext
     * (equal to toArray()) and does not crash. The host warns separately.
     */
    public function testStorageArrayWithoutCipherStoresAsIs(): void
    {
        $settings = new TraktSettings(
            accessToken: 'plain-access',
            refreshToken: 'plain-refresh',
        );

        $stored = $settings->toStorageArray(null);

        $this->assertSame('plain-access', $stored['access_token']);
        $this->assertSame('plain-refresh', $stored['refresh_token']);
        $this->assertSame($settings->toArray(), $stored);
    }

    /**
     * Legacy plaintext tokens (written before encryption was enabled) still
     * load: the sodium cipher passes through values lacking its version marker.
     */
    public function testFromArrayWithCipherPassesThroughLegacyPlaintext(): void
    {
        $cipher = new SodiumTokenCipher(str_repeat("\x22", SODIUM_CRYPTO_SECRETBOX_KEYBYTES));

        $restored = TraktSettings::fromArray([
            'access_token' => 'legacy-plain-access',
            'refresh_token' => 'legacy-plain-refresh',
        ], $cipher);

        $this->assertSame('legacy-plain-access', $restored->accessToken);
        $this->assertSame('legacy-plain-refresh', $restored->refreshToken);
    }

    /**
     * The SPA projection NEVER contains the raw token strings; it exposes a
     * has_tokens flag and the editable preferences instead.
     */
    public function testSpaArrayRedactsRawTokens(): void
    {
        $settings = new TraktSettings(
            accessToken: 'secret-access-leak',
            refreshToken: 'secret-refresh-leak',
            expiresAt: 1700000000,
            syncEnabled: false,
            syncIntervalMinutes: 45,
            scrobbleEnabled: true,
            username: 'bob',
        );

        $spa = $settings->toSpaArray();

        // No raw token strings appear anywhere in the projection.
        $serialized = json_encode($spa);
        $this->assertIsString($serialized);
        $this->assertStringNotContainsString('secret-access-leak', $serialized);
        $this->assertStringNotContainsString('secret-refresh-leak', $serialized);
        $this->assertArrayNotHasKey('access_token', $spa);
        $this->assertArrayNotHasKey('refresh_token', $spa);

        // Connection status is exposed without the raw values.
        $this->assertTrue($spa['has_tokens']);
        $this->assertSame('bob', $spa['username']);
        $this->assertFalse($spa['sync_enabled']);
        $this->assertSame(45, $spa['sync_interval_minutes']);
        $this->assertSame(1700000000, $spa['token_expires_at']);
    }

    /**
     * has_tokens is false in the SPA projection when not connected.
     */
    public function testSpaArrayHasTokensFalseWhenNotConnected(): void
    {
        $spa = (new TraktSettings())->toSpaArray();

        $this->assertFalse($spa['has_tokens']);
        $this->assertArrayNotHasKey('access_token', $spa);
        $this->assertArrayNotHasKey('refresh_token', $spa);
    }

    /**
     * fromConfig() decodes raw/base64/hex 32-byte keys and rejects junk.
     */
    public function testSodiumCipherFromConfigKeyEncodings(): void
    {
        $raw = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);

        $this->assertInstanceOf(SodiumTokenCipher::class, SodiumTokenCipher::fromConfig($raw));
        $this->assertInstanceOf(SodiumTokenCipher::class, SodiumTokenCipher::fromConfig(base64_encode($raw)));
        $this->assertInstanceOf(SodiumTokenCipher::class, SodiumTokenCipher::fromConfig(bin2hex($raw)));

        // No / invalid key → null (caller degrades to store-as-is).
        $this->assertNull(SodiumTokenCipher::fromConfig(null));
        $this->assertNull(SodiumTokenCipher::fromConfig(''));
        $this->assertNull(SodiumTokenCipher::fromConfig('too-short'));
    }
}

/**
 * Deterministic, reversible fake cipher for unit tests: prefixes a marker so
 * the test can prove the stored value is transformed (not the raw token) while
 * still round-tripping.
 *
 * @internal test seam
 */
final class FakeReversibleCipher implements TokenCipher
{
    private const PREFIX = 'enc::';

    public function encrypt(string $plain): string
    {
        return self::PREFIX . base64_encode(strrev($plain));
    }

    public function decrypt(string $cipher): string
    {
        if (!str_starts_with($cipher, self::PREFIX)) {
            return $cipher;
        }

        $decoded = base64_decode(substr($cipher, strlen(self::PREFIX)), true);

        return $decoded === false ? $cipher : strrev($decoded);
    }
}
