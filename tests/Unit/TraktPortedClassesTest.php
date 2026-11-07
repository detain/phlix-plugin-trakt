<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins\Scrobbler\Trakt;

use Phlix\Plugins\Scrobbler\Trakt\DbTraktOAuthStateStore;
use Phlix\Plugins\Scrobbler\Trakt\OAuthStateStoreUnavailableException;
use Phlix\Plugins\Scrobbler\Trakt\TraktApiException;
use Phlix\Plugins\Scrobbler\Trakt\TraktOAuthStateStore;
use Phlix\Plugins\Scrobbler\Trakt\TraktOperatorConfig;
use Phlix\Plugins\Scrobbler\Trakt\TraktRateLimitException;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;
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
        // Verify cleanup DELETE was called (3rd call) — and that it is
        // PROVIDER-SCOPED (review trakt__secaudit #5): the oauth_state_store
        // table is shared with OIDC/GitHub/WebAuthn flows and the trakt
        // sweep must never touch their rows.
        $calls = $db->getCalls();
        $this->assertCount(3, $calls);
        $this->assertStringContainsString('DELETE FROM oauth_state_store WHERE provider = ? AND expires_at', $calls[2]['sql']);
        $this->assertSame(['trakt', 100], $calls[2]['params']);
    }

    /**
     * Law pin (review trakt__secaudit #5 / trakt__srphp m13): the one-shot
     * consume must take a row lock (FOR UPDATE — the estate B2 idiom). A
     * plain SELECT inside the transaction lets two concurrent callbacks on
     * different workers each read the row from their own snapshot and both
     * receive the code verifier; the FOR UPDATE arm makes the second writer
     * wait, and the committed delete then comes back empty.
     */
    public function testDbStoreConsumeSelectsUnderRowLock(): void
    {
        $db = new TestableConnection();
        $db->setResults([[], [], []]);
        $store = new DbTraktOAuthStateStore($db);

        $this->assertNull($store->consume('state-never-issued'));

        $calls = $db->getCalls();
        $this->assertStringContainsString('SELECT data FROM oauth_state_store', $calls[0]['sql']);
        $this->assertStringContainsString('FOR UPDATE', $calls[0]['sql']);
    }

    /**
     * Law pin (review trakt__srphp m13 tail): a failing cleanup DELETE may
     * not silently regrow the table — it must log. The consume() verdict is
     * already decided at that point, so the failure stays non-fatal.
     */
    public function testDbStoreCleanupFailureIsLoggedLoudly(): void
    {
        $db = new TestableConnection();
        // SELECT row, consume DELETE ok, cleanup DELETE FAILS.
        $db->setResults([
            [['data' => json_encode(['code_verifier' => 'verifier-abc'])]],
            [],
            false,
        ]);

        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with($this->stringContains('cleanup'));

        $store = new DbTraktOAuthStateStore($db, 600, $logger);

        $this->assertSame('verifier-abc', $store->consume('state-123'));
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

    /**
     * PG-137 (review trakt__srphp m14): put() json_encode'd the payload
     * unchecked. An unencodable verifier (invalid UTF-8 — the value is
     * attacker-influenced input traveling from the OAuth dance) produced
     * `false`, which bound as an empty column: the row inserted fine and
     * consume() answered "never issued" forever — a dead link with a
     * green write path. put() must fail loudly BEFORE touching the table.
     */
    public function testDbStorePutFailsLoudlyWhenThePayloadCannotBeEncoded(): void
    {
        $db = new TestableConnection();
        $store = new DbTraktOAuthStateStore($db);

        $thrown = null;
        try {
            $store->put('state-bad-utf8', "\xFF\xFE not valid utf-8");
        } catch (\Throwable $t) {
            $thrown = $t;
        }

        $this->assertInstanceOf(
            \RuntimeException::class,
            $thrown,
            'PG-137: an unencodable verifier must reject put(), not store a dead row',
        );
        $this->assertStringContainsString('encode', (string) $thrown?->getMessage());
        $this->assertSame([], $db->getCalls(), 'no INSERT may be attempted with an unencodable payload');
    }

    /**
     * PG-137: a FAILED SELECT (`query()` returns false — the upstream
     * contract) is an infrastructure fault, not "state never issued".
     * The parent blended it into the CSRF-null arm, so a callback hitting
     * a broken pool read back as a replay rejection.
     */
    public function testDbStoreConsumeTreatsSelectFailureAsStoreUnavailableNotCsrfNull(): void
    {
        $db = new TestableConnection();
        $db->setResults([false]);
        $store = new DbTraktOAuthStateStore($db);

        $this->expectException(OAuthStateStoreUnavailableException::class);

        $store->consume('state-123');
    }

    /**
     * PG-137: the row was found but its DELETE failed — the one-shot
     * guarantee is broken and the state could replay. That is an
     * infrastructure fault, not a verdict of "not yours"; typed failure.
     */
    public function testDbStoreConsumeTreatsDeleteFailureAsStoreUnavailable(): void
    {
        $db = new TestableConnection();
        // SELECT returns the row, then the DELETE itself fails.
        $db->setResults([
            [['data' => json_encode(['code_verifier' => 'verifier-abc'])]],
            false,
        ]);
        $store = new DbTraktOAuthStateStore($db);

        $this->expectException(OAuthStateStoreUnavailableException::class);

        $store->consume('state-123');
    }

    /**
     * PG-137: a connection fault that THROWS was swallowed into the
     * CSRF-null arm (`catch(Throwable) → return null`) — the register's
     * exact complaint: "consume answers a replay rejection for an
     * infrastructure failure". It must surface as the typed, LOUDLY
     * logged store-unavailability fault instead.
     */
    public function testDbStoreConsumeMapsConnectionFaultToTypedFailureAndLogsLoudly(): void
    {
        $fault = new \RuntimeException('connection lost');
        $db = new class ($fault) extends Connection {
            public function __construct(private readonly \Throwable $queryFault)
            {
                parent::__construct('localhost', '3306', 'user', 'pass', 'test');
            }

            public function query(string $sql, array $params = []): array|false
            {
                throw $this->queryFault;
            }
        };

        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with($this->stringContains('unavailable'));

        $store = new DbTraktOAuthStateStore($db, 600, $logger);

        $this->expectException(OAuthStateStoreUnavailableException::class);

        $store->consume('state-123');
    }

    /**
     * PG-137: on a dead connection even the ROLLBACK can throw. The
     * parent let that raw rollback exception escape from inside its own
     * catch block (register: "rollback can throw out of the handler on a
     * dead connection anyway"). The guarded rollback must convert to the
     * typed fault, never leak a foreign exception.
     */
    public function testDbStoreRollbackFaultCannotMaskTheTypedStoreFailure(): void
    {
        $db = new class extends Connection {
            public function __construct()
            {
                parent::__construct('localhost', '3306', 'user', 'pass', 'test');
            }

            public function query(string $sql, array $params = []): array|false
            {
                return false; // SELECT fails; the handler takes the rollback arm
            }

            public function rollBackTrans(): void
            {
                throw new \RuntimeException('rollback boom');
            }
        };

        $store = new DbTraktOAuthStateStore($db, 600, new \Psr\Log\NullLogger());

        $this->expectException(OAuthStateStoreUnavailableException::class);

        $store->consume('state-123');
    }

    /**
     * PG-137 no-change pin: a genuine "no rows" SELECT (empty array) is a
     * TRUE miss and keeps answering the CSRF-law null. Only failures
     * change identity; the never-issued/replayed paths stay byte-ident.
     */
    public function testDbStoreConsumeKeepsAnsweringCsrfNullForGenuineMisses(): void
    {
        $db = new TestableConnection();
        $db->setResults([[], [], []]);
        $store = new DbTraktOAuthStateStore($db);

        $this->assertNull($store->consume('never-issued'));
    }

    // --- phpstan baseline (PG-138) ------------------------------------------

    /**
     * PG-138 (review trakt__srphp m15): the static-analysis baseline used
     * to silence whole ERROR CATEGORIES with wildcards — any new invalid
     * type, any unverified `object::` method call, any bad instantiation
     * passed level 9 in total silence. It is now an enumerated per-class
     * baseline naming each host-supplied type individually. This pin
     * keeps the file from regressing to wildcards and from losing an
     * enumerated class on the way.
     */
    public function testThePhpstanBaselineEnumeratesHostClassesInsteadOfWildcards(): void
    {
        $neon = (string) file_get_contents(dirname(__DIR__, 2) . '/phpstan.neon');

        $this->assertStringContainsString('level: 9', $neon);
        $this->assertStringNotContainsString(
            '.*',
            $neon,
            'PG-138: the baseline must enumerate concrete classes — wildcards re-silence the category',
        );
        $this->assertStringNotContainsString(
            'object::',
            $neon,
            'PG-138: the object:: silencer stays retired; HttpClient narrows the response by type now',
        );

        foreach ([
            'Phlix\\\\Auth\\\\WatchHistory',
            'Phlix\\\\Media\\\\Library\\\\MediaItem',
            'Phlix\\\\Media\\\\Library\\\\ItemRepository',
            'Phlix\\\\Admin\\\\SettingsRepository',
            'Phlix\\\\Common\\\\Uuid',
            'Workerman\\\\MySQL\\\\Connection',
            'Workerman\\\\Timer',
            'Workerman\\\\Http\\\\Client',
        ] as $hostClass) {
            $this->assertStringContainsString(
                $hostClass,
                $neon,
                "PG-138: enumerated baseline must name {$hostClass}",
            );
        }
    }

    // --- retired public surface (PG-141) -------------------------------------

    /**
     * PG-141 (review trakt__srphp m22 [Info]): TraktPluginInterface was an
     * empty, documentation-only marker — zero references in src, tests or
     * plugin.json, and the host never resolved it. It is deleted; this pin
     * keeps it gone (a re-add would resurrect a seam nothing wires to).
     */
    public function testTheDocumentationMarkerInterfaceIsRetired(): void
    {
        $this->assertFileDoesNotExist(
            dirname(__DIR__, 2) . '/src/TraktPluginInterface.php',
            'PG-141: the empty marker interface must stay deleted',
        );
        $this->assertFalse(
            class_exists('Phlix\Plugins\Scrobbler\Trakt\TraktPluginInterface', false),
            'PG-141: the retired marker class must not be loaded from anywhere',
        );
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
