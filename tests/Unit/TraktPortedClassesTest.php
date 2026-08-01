<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins\Scrobbler\Trakt;

use Phlix\Plugins\Scrobbler\Trakt\DbTraktOAuthStateStore;
use Phlix\Plugins\Scrobbler\Trakt\TraktApiException;
use Phlix\Plugins\Scrobbler\Trakt\TraktOAuthStateStore;
use Phlix\Plugins\Scrobbler\Trakt\TraktOperatorConfig;
use Phlix\Plugins\Scrobbler\Trakt\TraktRateLimitException;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\TestableConnection;

/**
 * Covers the three classes ported into this repo from the (stale) server-embedded
 * copy so the catalog plugin is a complete superset the host can run once the
 * embedded copy is deleted (consolidation Phase A / task A1).
 */
final class TraktPortedClassesTest extends TestCase
{
    // --- TraktRateLimitException -------------------------------------------

    public function testRateLimitExceptionExtendsApiExceptionWith429AndRetryAfter(): void
    {
        $e = new TraktRateLimitException('slow down', 42);

        $this->assertInstanceOf(TraktApiException::class, $e);
        $this->assertSame(429, $e->getCode());
        $this->assertSame('slow down', $e->getMessage());
        $this->assertSame(42, $e->retryAfter);
    }

    public function testRateLimitExceptionDefaults(): void
    {
        $e = new TraktRateLimitException();

        $this->assertSame(429, $e->getCode());
        $this->assertSame('Rate limit exceeded', $e->getMessage());
        $this->assertSame(0, $e->retryAfter);
    }

    // --- DbTraktOAuthStateStore --------------------------------------------

    public function testDbStoreImplementsInterface(): void
    {
        $store = new DbTraktOAuthStateStore(new TestableConnection());

        $this->assertInstanceOf(TraktOAuthStateStore::class, $store);
    }

    public function testDbStorePutInsertsStateAndVerifier(): void
    {
        $db = new TestableConnection();
        $store = new DbTraktOAuthStateStore($db);

        $store->put('state-123', 'verifier-abc');

        $calls = $db->getCalls();
        $this->assertCount(1, $calls);
        $this->assertStringContainsString('INSERT INTO oauth_state_store', $calls[0]['sql']);

        // params order: [id, provider, state_value, data, expires_at]
        $params = $calls[0]['params'];
        $this->assertSame('trakt', $params[1]);
        $this->assertSame('state-123', $params[2]);

        $data = json_decode((string) $params[3], true);
        $this->assertIsArray($data);
        $this->assertSame('verifier-abc', $data['code_verifier']);
    }

    public function testDbStoreConsumeReturnsVerifierAndDeletesEntry(): void
    {
        $db = new TestableConnection();
        // 1) SELECT returns the stored row, 2) DELETE, 3) cleanup DELETE.
        $db->setResults([
            [['data' => json_encode(['code_verifier' => 'verifier-abc'])]],
            [],
            [],
        ]);
        $store = new DbTraktOAuthStateStore($db);

        $verifier = $store->consume('state-123');

        $this->assertSame('verifier-abc', $verifier);

        // One-shot: a DELETE for this specific state must have been issued.
        $deletedOwnState = array_filter(
            $db->getCalls(),
            static fn (array $c): bool => str_contains($c['sql'], 'DELETE FROM oauth_state_store WHERE provider'),
        );
        $this->assertNotEmpty($deletedOwnState, 'consume() must delete the consumed state (one-shot)');
    }

    public function testDbStoreConsumeReturnsNullForUnknownState(): void
    {
        $db = new TestableConnection();
        // SELECT finds nothing, then the lazy cleanup DELETE runs.
        $db->setResults([[], []]);
        $store = new DbTraktOAuthStateStore($db);

        $this->assertNull($store->consume('never-issued'));
    }

    public function testDbStorePutInsertsWithCorrectTtlParameter(): void
    {
        $db = new TestableConnection();
        $store = new DbTraktOAuthStateStore($db, 300); // 5 minutes TTL

        $store->put('state-123', 'verifier-abc');

        $calls = $db->getCalls();
        $this->assertCount(1, $calls);
        $this->assertStringContainsString('INSERT INTO oauth_state_store', $calls[0]['sql']);

        // Verify the expires_at parameter is ~5 minutes in the future
        $params = $calls[0]['params'];
        $expiresAt = $params[4] ?? null;
        $this->assertNotNull($expiresAt);
        // expires_at should be time() + 300 (with small tolerance)
        $this->assertGreaterThanOrEqual(time() + 299, $expiresAt);
        $this->assertLessThanOrEqual(time() + 301, $expiresAt);
    }

    public function testDbStorePutInsertsWithDefaultTtlWhenZeroProvided(): void
    {
        $db = new TestableConnection();
        // Passing 0 as TTL should fall back to default (600 seconds)
        $store = new DbTraktOAuthStateStore($db, 0);

        $store->put('state-xyz', 'verifier-456');

        $calls = $db->getCalls();
        $params = $calls[0]['params'];
        $expiresAt = $params[4] ?? null;
        $this->assertNotNull($expiresAt);
        // Default TTL is 600 seconds, so expires_at should be time() + 600
        $this->assertGreaterThanOrEqual(time() + 599, $expiresAt);
        $this->assertLessThanOrEqual(time() + 601, $expiresAt);
    }

    public function testDbStoreConsumeCleanupCalledEvenOnSuccess(): void
    {
        $db = new TestableConnection();
        // SELECT returns the stored row, DELETE, cleanup DELETE
        $db->setResults([
            [['data' => json_encode(['code_verifier' => 'verifier-abc'])]],
            [],
            [],
        ]);
        $store = new DbTraktOAuthStateStore($db);

        $verifier = $store->consume('state-123');

        $this->assertSame('verifier-abc', $verifier);
        // Verify cleanup DELETE was called (3rd call)
        $calls = $db->getCalls();
        $this->assertCount(3, $calls);
        $this->assertStringContainsString('DELETE FROM oauth_state_store WHERE expires_at', $calls[2]['sql']);
    }

    public function testDbStoreConsumeWithEmptyCodeVerifierReturnsNull(): void
    {
        $db = new TestableConnection();
        // SELECT returns a row with empty code_verifier
        $db->setResults([
            [['data' => json_encode(['code_verifier' => ''])]],
            [],
            [],
        ]);
        $store = new DbTraktOAuthStateStore($db);

        $result = $store->consume('state-123');

        $this->assertNull($result);
    }

    public function testDbStoreConsumeWithMalformedJsonReturnsNull(): void
    {
        $db = new TestableConnection();
        // SELECT returns a row with invalid JSON
        $db->setResults([
            [['data' => 'not-valid-json{']],
            [],
            [],
        ]);
        $store = new DbTraktOAuthStateStore($db);

        $result = $store->consume('state-123');

        $this->assertNull($result);
    }

    public function testDbStoreConsumeWithMissingDataFieldReturnsNull(): void
    {
        $db = new TestableConnection();
        // SELECT returns a row with no data field
        $db->setResults([
            [[]],
            [],
            [],
        ]);
        $store = new DbTraktOAuthStateStore($db);

        $result = $store->consume('state-123');

        $this->assertNull($result);
    }

    // --- TraktOperatorConfig -----------------------------------------------

    public function testSettingKeyMapCoversTheThreeCredentialKeys(): void
    {
        $this->assertSame(
            [
                'trakt.client_id'     => 'client_id',
                'trakt.client_secret' => 'client_secret',
                'trakt.redirect_uri'  => 'redirect_uri',
            ],
            TraktOperatorConfig::SETTING_KEY_MAP,
        );
    }

    public function testApplyOverridesOverlaysAdminSavedCredentials(): void
    {
        $config = ['client_id' => 'file-id', 'client_secret' => 'file-secret'];

        $settings = new \Phlix\Admin\SettingsRepository([
            'trakt.client_id'    => ['value' => 'admin-id'],
            'trakt.redirect_uri' => ['value' => 'https://admin/callback'],
        ]);

        $result = TraktOperatorConfig::applyOverrides($config, $settings);

        // Saved override beats the file literal.
        $this->assertSame('admin-id', $result['client_id']);
        // A key with no override keeps the file literal.
        $this->assertSame('file-secret', $result['client_secret']);
        // A saved-only key is added.
        $this->assertSame('https://admin/callback', $result['redirect_uri']);
    }

    public function testApplyOverridesWithNullSettingsReturnsConfigUnchanged(): void
    {
        $config = ['client_id' => 'file-id'];

        $this->assertSame($config, TraktOperatorConfig::applyOverrides($config, null));
    }

    public function testApplyOverridesIgnoresEmptyStringOverride(): void
    {
        $config = ['client_id' => 'file-id'];

        // A cleared field (empty string) must NOT blank a real env/file credential.
        $settings = new \Phlix\Admin\SettingsRepository([
            'trakt.client_id' => ['value' => ''],
        ]);

        $result = TraktOperatorConfig::applyOverrides($config, $settings);

        $this->assertSame('file-id', $result['client_id']);
    }

    // --- TraktOperatorConfig::load() ---------------------------------------

    public function testLoadReturnsEmptyArrayWhenConfigFileNotFound(): void
    {
        $result = TraktOperatorConfig::load('/nonexistent/path/config.php', null);

        $this->assertSame([], $result);
    }

    public function testLoadReadsConfigFileAndAppliesOverrides(): void
    {
        // Create a temporary config file
        $tmpFile = sys_get_temp_dir() . '/trakt_test_config_' . uniqid() . '.php';
        file_put_contents($tmpFile, '<?php return [\'client_id\' => \'file-id\', \'client_secret\' => \'file-secret\'];');

        try {
            $settings = new \Phlix\Admin\SettingsRepository([
                'trakt.client_id' => ['value' => 'admin-id'],
            ]);

            $result = TraktOperatorConfig::load($tmpFile, $settings);

            // Override should be applied
            $this->assertSame('admin-id', $result['client_id']);
            // Non-overridden value should remain
            $this->assertSame('file-secret', $result['client_secret']);
        } finally {
            unlink($tmpFile);
        }
    }

    public function testLoadReturnsEmptyArrayForNonArrayConfigFile(): void
    {
        // Create a temporary config file that returns a non-array
        $tmpFile = sys_get_temp_dir() . '/trakt_test_config_' . uniqid() . '.php';
        file_put_contents($tmpFile, '<?php return "not an array";');

        try {
            $result = TraktOperatorConfig::load($tmpFile, null);

            $this->assertSame([], $result);
        } finally {
            unlink($tmpFile);
        }
    }
}
