<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins\Scrobbler\Trakt;

use Phlix\Plugins\Scrobbler\Trakt\HttpClientInterface;
use Phlix\Plugins\Scrobbler\Trakt\TraktApi;
use Phlix\Plugins\Scrobbler\Trakt\TraktApiException;
use Phlix\Plugins\Scrobbler\Trakt\TraktAuthenticationException;
use Phlix\Plugins\Scrobbler\Trakt\TokenCipher;
use Phlix\Plugins\Scrobbler\Trakt\TraktHistorySync;
use Phlix\Plugins\Scrobbler\Trakt\TraktPlugin;
use Phlix\Plugins\Scrobbler\Trakt\TraktSettings;
use Phlix\Plugins\Scrobbler\Trakt\TraktSettingsRepository;
use Phlix\Plugins\Scrobbler\Trakt\SodiumTokenCipher;
use Phlix\Shared\Events\Playback\PlaybackPaused;
use Phlix\Shared\Events\Playback\PlaybackResumed;
use Phlix\Shared\Events\Playback\PlaybackStarted;
use Phlix\Shared\Events\Playback\PlaybackStopped;
use Phlix\Shared\Plugin\ConfigurableInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class TraktPluginTest extends TestCase
{
    public function testSubscribedEventsReturnsExpectedEvents(): void
    {
        $plugin = new TraktPlugin(new TraktSettings(), new NullLogger());

        $events = $plugin->subscribedEvents();

        $this->assertArrayHasKey(PlaybackStarted::class, $events);
        $this->assertArrayHasKey(PlaybackStopped::class, $events);
        $this->assertArrayHasKey(PlaybackPaused::class, $events);
        $this->assertArrayHasKey(PlaybackResumed::class, $events);
        $this->assertSame('onPlaybackStarted', $events[PlaybackStarted::class]);
        $this->assertSame('onPlaybackStopped', $events[PlaybackStopped::class]);
        $this->assertSame('onPlaybackPaused', $events[PlaybackPaused::class]);
        $this->assertSame('onPlaybackResumed', $events[PlaybackResumed::class]);
    }

    /**
     * subscribedEvents() must be an exact bijection with the handler methods:
     * the event set is EXACTLY the four host-dispatched playback events, every
     * subscribed handler EXISTS and is public, and each handler's single
     * parameter is type-hinted to the very event class it is mapped from (no
     * event without a handler, no handler subscribed to the wrong event).
     *
     * This locks the resolved "4-events" answer (Trakt's 3-state protocol:
     * start/pause/stop + resume→start, mapped onto the four events the host
     * fires from PlaybackController) and guards against the stale "2-event"
     * shape that the server-embedded copy still carries.
     */
    public function testSubscribedEventsFormBijectionWithHandlers(): void
    {
        $plugin = new TraktPlugin(new TraktSettings(), new NullLogger());
        $events = $plugin->subscribedEvents();

        $expected = [
            PlaybackStarted::class => 'onPlaybackStarted',
            PlaybackPaused::class => 'onPlaybackPaused',
            PlaybackResumed::class => 'onPlaybackResumed',
            PlaybackStopped::class => 'onPlaybackStopped',
        ];

        // Exact key set — no event without a handler, no extra event.
        $this->assertEqualsCanonicalizing(array_keys($expected), array_keys($events));

        $reflection = new \ReflectionObject($plugin);
        foreach ($events as $eventClass => $handler) {
            $this->assertIsString($handler);
            $this->assertSame($expected[$eventClass], $handler, "wrong handler for {$eventClass}");

            // Handler must exist and be public.
            $this->assertTrue($reflection->hasMethod($handler), "missing handler {$handler}");
            $method = $reflection->getMethod($handler);
            $this->assertTrue($method->isPublic(), "handler {$handler} not public");

            // Handler's single parameter must be typed to the mapped event class.
            $params = $method->getParameters();
            $this->assertCount(1, $params, "handler {$handler} must take exactly one arg");
            $type = $params[0]->getType();
            $this->assertInstanceOf(\ReflectionNamedType::class, $type);
            $this->assertSame($eventClass, $type->getName(), "handler {$handler} typed to wrong event");
        }
    }

    /**
     * The boot-safe WIRE step must perform NO network/token I/O. onEnable()
     * resolves host services + constructs the API client + arms the sync timer,
     * but must never hit Trakt (no scrobble, no token refresh, no history pull).
     *
     * We inject a FakeTraktApi (which counts every network call) and a fresh
     * connected settings profile whose token is EXPIRED — the strongest trap,
     * because a careless onEnable that eagerly refreshed the token would flip a
     * counter here. After onEnable() every network counter must still be zero.
     */
    public function testOnEnableDoesNoBlockingIo(): void
    {
        $api = new FakeTraktApi();

        $settings = new TraktSettings(
            accessToken: 'old-access',
            refreshToken: 'old-refresh',
            expiresAt: time() - 3600, // expired: tempts an eager refresh
            syncEnabled: true,
            username: 'testuser',
        );

        $plugin = new TraktPlugin($settings, new NullLogger(), $api, new RecordingSettingsRepository());

        $container = new StubContainer([
            \Phlix\Media\Library\ItemRepository::class => new FakeItemRepository(),
            \Phlix\Auth\WatchHistory::class => null,
        ]);

        $plugin->onEnable($container);

        $this->assertSame(0, $api->refreshCalls, 'onEnable must not refresh the OAuth token');
        $this->assertSame(0, $api->scrobbleStartCalls, 'onEnable must not scrobble');
        $this->assertSame(0, $api->scrobbleStopCalls, 'onEnable must not scrobble');
        $this->assertSame(0, $api->scrobblePauseCalls, 'onEnable must not scrobble');
    }

    public function testImplementsConfigurableInterface(): void
    {
        $plugin = new TraktPlugin(new TraktSettings(), new NullLogger());

        $this->assertInstanceOf(ConfigurableInterface::class, $plugin);
    }

    public function testConfigureStoresSettings(): void
    {
        $plugin = new TraktPlugin(new TraktSettings(), new NullLogger());

        $plugin->configure([
            'enabled' => true,
            'access_token' => 'test-access',
            'refresh_token' => 'test-refresh',
            'username' => 'testuser',
            'scrobble_enabled' => true,
        ]);

        $settings = $plugin->getSettings();
        $this->assertSame('testuser', $settings->username);
        $this->assertSame('test-access', $settings->accessToken);
    }

    public function testGetSettingsReturnsCurrentSettings(): void
    {
        $settings = new TraktSettings(
            accessToken: 'stored-access',
            refreshToken: 'stored-refresh',
            username: 'storeduser'
        );
        $plugin = new TraktPlugin($settings, new NullLogger());

        $result = $plugin->getSettings();

        $this->assertSame('stored-access', $result->accessToken);
        $this->assertSame('stored-refresh', $result->refreshToken);
        $this->assertSame('storeduser', $result->username);
    }

    public function testSetAccessToken(): void
    {
        $plugin = new TraktPlugin(new TraktSettings(), new NullLogger());

        $plugin->setAccessToken('new-access-token');

        $settings = $plugin->getSettings();
        $this->assertSame('new-access-token', $settings->accessToken);
    }

    public function testSetRefreshToken(): void
    {
        $plugin = new TraktPlugin(new TraktSettings(), new NullLogger());

        $plugin->setRefreshToken('new-refresh-token');

        $settings = $plugin->getSettings();
        $this->assertSame('new-refresh-token', $settings->refreshToken);
    }

    public function testDefaultSettingsHaveSensibleDefaults(): void
    {
        $plugin = new TraktPlugin(new TraktSettings(), new NullLogger());

        $settings = $plugin->getSettings();

        $this->assertTrue($settings->syncEnabled);
        $this->assertSame(30, $settings->syncIntervalMinutes);
        $this->assertTrue($settings->scrobbleEnabled);
    }

    public function testConfigureWithDisabledScrobble(): void
    {
        $plugin = new TraktPlugin(new TraktSettings(), new NullLogger());

        $plugin->configure([
            'enabled' => true,
            'access_token' => 'test-access',
            'refresh_token' => 'test-refresh',
            'username' => 'testuser',
            'scrobble_enabled' => false,
        ]);

        $settings = $plugin->getSettings();
        $this->assertFalse($settings->scrobbleEnabled);
    }

    public function testConfigureWithDisabledSync(): void
    {
        $plugin = new TraktPlugin(new TraktSettings(), new NullLogger());

        $plugin->configure([
            'enabled' => true,
            'access_token' => 'test-access',
            'refresh_token' => 'test-refresh',
            'username' => 'testuser',
            'sync_enabled' => false,
        ]);

        $settings = $plugin->getSettings();
        $this->assertFalse($settings->syncEnabled);
    }

    /**
     * PG-126 (review trakt__srphp #2 [Major]): the host's PluginLoader
     * delivers configure() BEFORE onEnable() (phlix-server
     * src/Plugins/PluginLoader.php, "Deliver the persisted settings to the
     * plugin BEFORE onEnable()"), but the token cipher is only resolved
     * INSIDE onEnable() — so the boot hydration ran with a null cipher and
     * the at-rest envelopes stayed verbatim in settings->accessToken.
     * Nothing re-hydrated after the cipher arrived, and the
     * TokenDecryptionFailedException guard in configure() can never fire
     * for the container-resolved cipher (no cipher, no exception) — every
     * later Bearer call loops the envelope into 400/401s.
     *
     * The pre-fix suite hid the hole by injecting the cipher through the
     * constructor — a seam production never uses. This test drives the REAL
     * loader order with NO constructor cipher.
     */
    public function testTokensAreDecryptedWhenTheCipherResolvesAfterConfigure(): void
    {
        $cipher = new RecordingCipher();

        $storage = (new TraktSettings(
            accessToken: 'boot-access',
            refreshToken: 'boot-refresh',
            expiresAt: time() + 3600,
            username: 'testuser',
        ))->toStorageArray($cipher);

        // The persisted payload must be ciphertext, else the fixture proves nothing.
        $this->assertSame('ENC(' . base64_encode('boot-access') . ')', $storage['access_token']);

        $plugin = new TraktPlugin(logger: new NullLogger());
        $plugin->configure(['enabled' => true] + $storage);

        // Before onEnable the cipher is null by loader design: the envelope is
        // all the plugin can hold at this point (verbatim — that alone is not
        // the bug; the bug is that it stayed this way).
        $this->assertStringStartsWith('ENC(', (string) $plugin->getSettings()->accessToken);

        // The host resolves the cipher exactly here — after configure() ran.
        $plugin->onEnable(new StubContainer([TokenCipher::class => $cipher]));

        $settings = $plugin->getSettings();
        $this->assertSame(
            'boot-access',
            $settings->accessToken,
            'tokens hydrated while the cipher was still null must be re-read through the resolved cipher'
        );
        $this->assertSame('boot-refresh', $settings->refreshToken);
        $this->assertTrue($settings->isConfigured(), 'the account must read as linked, not as an envelope');
    }

    /**
     * PG-126 companion law: re-hydration must NOT resurrect stale
     * configure() input over settings injected directly (constructor seam,
     * or a configure() call that never happened). onEnable() without a
     * preceding configure() leaves the injected settings untouched.
     */
    public function testOnEnableWithoutConfigureKeepsConstructorSettingsUntouched(): void
    {
        $cipher = new RecordingCipher();
        $plugin = new TraktPlugin(
            settings: new TraktSettings(
                accessToken: 'injected-access',
                refreshToken: 'injected-refresh',
                username: 'testuser',
            ),
            logger: new NullLogger(),
        );

        $plugin->onEnable(new StubContainer([TokenCipher::class => $cipher]));

        $this->assertSame('injected-access', $plugin->getSettings()->accessToken);
        $this->assertSame('injected-refresh', $plugin->getSettings()->refreshToken);
    }

    /**
     * M-TRAKT-20 (srphp m12): when configure() receives envelopes encrypted
     * under a DIFFERENT key (operator key rotation), the plugin must degrade
     * to UNCONNECTED — prefs survive, tokens are dropped so hasTokens() is
     * false and the reconnect gate applies — instead of riding the literal
     * 'v1:...' ciphertext as a Bearer token. No exception escapes configure.
     */
    public function testConfigureDegradesToUnconnectedOnUndecryptableEnvelope(): void
    {
        $oldKey = str_repeat("\x11", SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        $newKey = str_repeat("\x22", SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        $oldCipher = new SodiumTokenCipher($oldKey);

        $plugin = new TraktPlugin(
            settings: new TraktSettings(),
            logger: new NullLogger(),
            tokenCipher: new SodiumTokenCipher($newKey),
        );

        $plugin->configure([
            'enabled' => true,
            'access_token' => $oldCipher->encrypt('rotated-out-access'),
            'refresh_token' => $oldCipher->encrypt('rotated-out-refresh'),
            'username' => 'stale-user',
            'sync_enabled' => false,
        ]);

        $settings = $plugin->getSettings();
        $this->assertFalse($settings->hasTokens(), 'undecryptable envelopes must not count as token custody');
        $this->assertFalse($settings->isConfigured(), 'the account must read as needing a fresh link');
        $this->assertNull($settings->accessToken);
        $this->assertNull($settings->expiresAt);
        // The non-custody preferences survive the degrade untouched:
        $this->assertFalse($settings->syncEnabled);
        $this->assertSame('stale-user', $settings->username);
    }

    // --- onDisable -----------------------------------------------------------

    public function testOnDisableClearsReferences(): void
    {
        $api = new FakeTraktApi();
        $plugin = new TraktPlugin(
            $this->freshSettings(),
            new NullLogger(),
            $api,
            new RecordingSettingsRepository(),
        );

        // Use reflection to set internal state before onDisable
        $ref = new \ReflectionClass($plugin);
        $itemRepoProp = $ref->getProperty('itemRepository');
        $itemRepoProp->setAccessible(true);
        $itemRepoProp->setValue($plugin, new FakeItemRepository());

        $watchHistProp = $ref->getProperty('watchHistory');
        $watchHistProp->setAccessible(true);
        $watchHistProp->setValue($plugin, new \Phlix\Auth\WatchHistory());

        $plugin->onDisable();

        $this->assertNull($itemRepoProp->getValue($plugin));
        $this->assertNull($watchHistProp->getValue($plugin));
    }

    // --- runScheduledSync ----------------------------------------------------

    public function testRunScheduledSyncDoesNothingWhenNotConfigured(): void
    {
        $api = new FakeTraktApi();
        $settings = new TraktSettings(
            accessToken: null,
            refreshToken: null,
            username: '', // not configured
            syncEnabled: true,
        );

        $plugin = new TraktPlugin($settings, new NullLogger(), $api);
        // Master switch ON: the missing credentials must be the sole reason the
        // tick skips (the gate below would also honour `enabled` — a false
        // master here would confound the cause this test pins).
        $plugin->configure(array_merge($settings->toArray(), ['enabled' => true]));

        // Use reflection to set internal collaborators for this test
        $ref = new \ReflectionClass($plugin);
        $apiProp = $ref->getProperty('api');
        $apiProp->setAccessible(true);
        $apiProp->setValue($plugin, $api);

        $watchHistProp = $ref->getProperty('watchHistory');
        $watchHistProp->setAccessible(true);
        $watchHistProp->setValue($plugin, new \Phlix\Auth\WatchHistory());

        $dbProp = $ref->getProperty('db');
        $dbProp->setAccessible(true);
        $dbProp->setValue($plugin, new \Workerman\MySQL\TestableConnection());

        // Use reflection to call runScheduledSync
        $method = $ref->getMethod('runScheduledSync');
        $method->setAccessible(true);
        $method->invoke($plugin);

        // No scrobble or refresh should happen when not configured
        $this->assertSame(0, $api->refreshCalls);
        $this->assertSame(0, $api->scrobbleStartCalls);
        $this->assertSame(0, $api->scrobbleStopCalls);
    }

    public function testRunScheduledSyncDoesNothingWhenSyncDisabled(): void
    {
        $api = new FakeTraktApi();
        $settings = new TraktSettings(
            accessToken: 'a',
            refreshToken: 'r',
            username: 'testuser',
            syncEnabled: false, // sync disabled
        );

        $plugin = new TraktPlugin($settings, new NullLogger(), $api);
        // Master switch ON: `sync_enabled = false` must be the sole blocker.
        $plugin->configure(array_merge($settings->toArray(), ['enabled' => true]));

        // Use reflection to set internal collaborators
        $ref = new \ReflectionClass($plugin);
        $apiProp = $ref->getProperty('api');
        $apiProp->setAccessible(true);
        $apiProp->setValue($plugin, $api);

        $watchHistProp = $ref->getProperty('watchHistory');
        $watchHistProp->setAccessible(true);
        $watchHistProp->setValue($plugin, new \Phlix\Auth\WatchHistory());

        $dbProp = $ref->getProperty('db');
        $dbProp->setAccessible(true);
        $dbProp->setValue($plugin, new \Workerman\MySQL\TestableConnection());

        $method = $ref->getMethod('runScheduledSync');
        $method->setAccessible(true);
        $method->invoke($plugin);

        $this->assertSame(0, $api->refreshCalls);
    }

    public function testRunScheduledSyncCallsHistorySyncOnConfiguredPlugin(): void
    {
        $api = new FakeTraktApi();
        $settings = new TraktSettings(
            accessToken: 'a',
            refreshToken: 'r',
            username: 'testuser',
            syncEnabled: true,
        );

        // Create a custom plugin that records makeHistorySync calls
        $seam = new RecordingHistorySync(
            $api,
            new \Phlix\Auth\WatchHistory(),
            $settings,
            new \Workerman\MySQL\TestableConnection(),
            new NullLogger(),
        );
        $seam->returnValue = 5;

        $plugin = new SeamTraktPlugin($settings, new NullLogger(), $api, new RecordingSettingsRepository());
        // Production wiring order: the host delivers settings via configure()
        // (master ON here) before onEnable()/ticks ever run.
        $plugin->configure(array_merge($settings->toArray(), ['enabled' => true]));
        $plugin->historySyncSeam = $seam;

        $ref = new \ReflectionClass($plugin);
        $watchHistProp = $ref->getParentClass()->getProperty('watchHistory');
        $watchHistProp->setAccessible(true);
        $watchHistProp->setValue($plugin, new \Phlix\Auth\WatchHistory());

        $dbProp = $ref->getParentClass()->getProperty('db');
        $dbProp->setAccessible(true);
        $dbProp->setValue($plugin, new \Workerman\MySQL\TestableConnection());

        $method = $ref->getMethod('runScheduledSync');
        $method->setAccessible(true);
        $method->invoke($plugin);

        $this->assertSame('default', $seam->lastProfileId);
    }

    /**
     * MASTER SWITCH (review trakt__docmaint M1): a tick with the plugin's
     * `enabled` master off must NOT pull Trakt history, no matter how the
     * per-feature `sync_enabled` toggle and credentials read. The README
     * Settings table and plugin.json sell `enabled` as "Trakt scrobbling
     * and sync master on/off"; the scheduled pull used to ignore it and kept
     * reconciling WatchHistory every tick with the switch off.
     *
     * Mutation-proven: reverting the `!$this->enabled` guard in
     * runScheduledSync() reddens this test (the seam records 'default').
     */
    public function testRunScheduledSyncSkipsPullWhenMasterSwitchOff(): void
    {
        $api = new FakeTraktApi();
        $settings = new TraktSettings(
            accessToken: 'a',
            refreshToken: 'r',
            username: 'testuser',
            syncEnabled: true, // per-feature sync ON — only the master blocks
        );

        $seam = new RecordingHistorySync(
            $api,
            new \Phlix\Auth\WatchHistory(),
            $settings,
            new \Workerman\MySQL\TestableConnection(),
            new NullLogger(),
        );

        $plugin = new SeamTraktPlugin($settings, new NullLogger(), $api, new RecordingSettingsRepository());
        $plugin->configure(array_merge($settings->toArray(), ['enabled' => false]));
        $plugin->historySyncSeam = $seam;

        $ref = new \ReflectionClass($plugin);
        $watchHistProp = $ref->getParentClass()->getProperty('watchHistory');
        $watchHistProp->setAccessible(true);
        $watchHistProp->setValue($plugin, new \Phlix\Auth\WatchHistory());

        $dbProp = $ref->getParentClass()->getProperty('db');
        $dbProp->setAccessible(true);
        $dbProp->setValue($plugin, new \Workerman\MySQL\TestableConnection());

        $method = $ref->getMethod('runScheduledSync');
        $method->setAccessible(true);
        $method->invoke($plugin);

        $this->assertSame('', $seam->lastProfileId, 'a tick with the master switch off must never reach the pull');
    }

    // --- B2: token-refresh wiring + persistence -----------------------------

    /**
     * (a) An expired token causes exactly ONE refresh on scrobble, and the
     * persisted settings carry the rotated tokens + a recomputed expires_at.
     */
    public function testScrobbleRefreshesExpiredTokenExactlyOnceAndPersists(): void
    {
        $repo = new RecordingSettingsRepository();
        $api = new FakeTraktApi();
        $api->refreshResult = [
            'access_token' => 'rotated-access',
            'refresh_token' => 'rotated-refresh',
            'expires_in' => 7200,
        ];

        $plugin = $this->makeWiredPlugin(
            api: $api,
            repo: $repo,
            settings: $this->expiredSettings(),
        );

        $before = time();
        $plugin->onPlaybackStopped($this->stopEvent());

        $this->assertSame(1, $api->refreshCalls, 'expected exactly one refresh');
        $this->assertSame(1, $api->scrobbleStopCalls, 'scrobble should still be submitted');

        $settings = $plugin->getSettings();
        $this->assertSame('rotated-access', $settings->accessToken);
        $this->assertSame('rotated-refresh', $settings->refreshToken);
        $this->assertNotNull($settings->expiresAt);
        $this->assertGreaterThanOrEqual($before + 7200, $settings->expiresAt);

        // (c) the persistence writer was invoked with the rotated token map.
        $this->assertNotEmpty($repo->saved, 'persistence writer must be invoked');
        $last = $repo->saved[count($repo->saved) - 1];
        $this->assertSame('rotated-access', $last['access_token']);
        $this->assertSame('rotated-refresh', $last['refresh_token']);
        $this->assertSame($settings->expiresAt, $last['expires_at']);
    }

    /**
     * A fresh (non-expired) token must NOT trigger a refresh before scrobble.
     */
    public function testFreshTokenDoesNotRefreshBeforeScrobble(): void
    {
        $repo = new RecordingSettingsRepository();
        $api = new FakeTraktApi();

        $plugin = $this->makeWiredPlugin(
            api: $api,
            repo: $repo,
            settings: $this->freshSettings(),
        );

        $plugin->onPlaybackStarted($this->startEvent());

        $this->assertSame(0, $api->refreshCalls, 'fresh token must not refresh');
        $this->assertSame(1, $api->scrobbleStartCalls);
        $this->assertSame([], $repo->saved, 'no token rotation, so nothing persisted');
    }

    /**
     * (b) A 401 on the first scrobble triggers a refresh + a SINGLE retry.
     */
    public function testAuthFailureTriggersRefreshAndSingleRetry(): void
    {
        $repo = new RecordingSettingsRepository();
        $api = new FakeTraktApi();
        $api->throwAuthOnFirstScrobble = true;
        $api->refreshResult = [
            'access_token' => 'recovered-access',
            'refresh_token' => 'recovered-refresh',
            'expires_in' => 3600,
        ];

        $plugin = $this->makeWiredPlugin(
            api: $api,
            repo: $repo,
            settings: $this->freshSettings(),
        );

        $plugin->onPlaybackStopped($this->stopEvent());

        $this->assertSame(1, $api->refreshCalls, 'one refresh after the 401');
        $this->assertSame(2, $api->scrobbleStopCalls, 'first call (401) + exactly one retry');

        $settings = $plugin->getSettings();
        $this->assertSame('recovered-access', $settings->accessToken);
        $this->assertSame('recovered-refresh', $settings->refreshToken);

        // The retry used the rotated access token.
        $this->assertSame('recovered-access', $api->lastScrobbleToken);

        // The rotated tokens were persisted.
        $this->assertNotEmpty($repo->saved);
        $last = $repo->saved[count($repo->saved) - 1];
        $this->assertSame('recovered-access', $last['access_token']);
    }

    /**
     * (d) PG-139 flipped the old law this test used to pin: without a
     * persistence writer the refresh is now REFUSED before the exchange —
     * Trakt one-time-rotates the refresh token, so rotating into memory
     * that cannot persist destroys the link at next boot. On the stop
     * path that means no exchange call, no scrobble (the PG-132 early
     * return), and the stored credential left byte-identical.
     */
    public function testRefreshWithoutPersistenceWriterIsDeclinedToPreserveTheCredential(): void
    {
        $api = new FakeTraktApi();
        $api->refreshResult = [
            'access_token' => 'rotated-access',
            'refresh_token' => 'rotated-refresh',
            'expires_in' => 7200,
        ];

        // No repository injected.
        $plugin = $this->makeWiredPlugin(
            api: $api,
            repo: null,
            settings: $this->expiredSettings(),
        );

        $plugin->onPlaybackStopped($this->stopEvent());

        $this->assertSame(0, $api->refreshCalls, 'custody gate refuses the exchange without a writer');
        $this->assertSame(0, $api->scrobbleStopCalls, 'declined credential short-circuits the scrobble');

        $settings = $plugin->getSettings();
        $this->assertSame('old-access', $settings->accessToken);
        $this->assertSame('old-refresh', $settings->refreshToken);
    }

    /**
     * setAccessToken / setRefreshToken route the rebuilt settings through the
     * SAME persist call (Q3 consolidation) so they are no longer dropped.
     */
    public function testSetTokensPersistThroughRepository(): void
    {
        $repo = new RecordingSettingsRepository();
        $plugin = new TraktPlugin(
            new TraktSettings(accessToken: 'a', refreshToken: 'r', username: 'u'),
            new NullLogger(),
            new FakeTraktApi(),
            $repo,
        );

        $plugin->setAccessToken('manual-access');
        $plugin->setRefreshToken('manual-refresh');

        $this->assertCount(2, $repo->saved);
        $this->assertSame('manual-access', $repo->saved[0]['access_token']);
        $this->assertSame('manual-refresh', $repo->saved[1]['refresh_token']);
        $this->assertSame('manual-access', $repo->saved[1]['access_token']);
    }

    /**
     * PG-139 (review trakt__srphp m19): a refresh cycle against a null
     * settings writer burned its own credential. Trakt ONE-TIME rotates
     * the refresh token per exchange; without a writer the rotated pair
     * lived only in process memory, so the next boot resurrected the
     * EXPIRED pair from storage — a dead link that no log ever named.
     * The missing writer is now a hard precondition once the plugin is
     * linked: the exchange is REFUSED before it can strand a credential.
     * On the parent this test is RED on both arms: the exchange happens
     * (refreshCalls 1) and ensureFreshToken returns true.
     */
    public function testTokenRotationIsRefusedWhenRotatedTokensCannotBePersisted(): void
    {
        $api = new FakeTraktApi();

        $plugin = new TraktPlugin($this->expiredSettings(), new NullLogger(), $api, null);

        $this->assertFalse(
            $plugin->ensureFreshToken(),
            'PG-139: without a settings writer a linked plugin must not rotate into oblivion',
        );
        $this->assertSame(
            0,
            $api->refreshCalls,
            'PG-139: the exchange must be refused BEFORE burning the one-time refresh token',
        );
    }

    /**
     * PG-139 invariant pin (green on both sides): with a writer bound,
     * rotation works exactly as before — the precondition gates custody
     * loss, not the refresh itself.
     */
    public function testTokenRotationStillProceedsWhenTheWriterExists(): void
    {
        $api = new FakeTraktApi();
        $repo = new RecordingSettingsRepository();

        $plugin = new TraktPlugin($this->expiredSettings(), new NullLogger(), $api, $repo);

        $this->assertTrue($plugin->ensureFreshToken());
        $this->assertSame(1, $api->refreshCalls);
        $this->assertCount(1, $repo->saved, 'the rotated pair must land in the writer');
    }

    /**
     * PG-139: with a linked account the no-writer persist is a credential
     * custody incident, not a cosmetic miss — it must log at ERROR, and
     * name the missing binding (parent logged a warning).
     */
    public function testManualTokenWriteWithoutWriterLogsAtErrorOnceLinked(): void
    {
        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with($this->stringContains('settings writer'));

        $plugin = new TraktPlugin(
            new TraktSettings(accessToken: 'a', refreshToken: 'r', username: 'u'),
            $logger,
            new FakeTraktApi(),
            null,
        );

        $plugin->setAccessToken('manual-access');
    }

    /**
     * PG-139 proportionality pin: an UNLINKED write (nothing to custody)
     * keeps the old warning-level no-op — the escalation is about lost
     * credentials, and there are none before the link exists.
     */
    public function testUnlinkedTokenWriteWithoutWriterStaysAtWarning(): void
    {
        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with($this->stringContains('not persisted'));
        $logger->expects($this->never())->method('error');

        $plugin = new TraktPlugin(new TraktSettings(), $logger, new FakeTraktApi(), null);

        $plugin->setRefreshToken('only-refresh');
    }

    /**
     * ensureFreshToken is a safe no-op (returns true) when the token is valid.
     */
    public function testEnsureFreshTokenReturnsTrueForValidToken(): void
    {
        $api = new FakeTraktApi();
        $plugin = new TraktPlugin(
            $this->freshSettings(),
            new NullLogger(),
            $api,
            new RecordingSettingsRepository(),
        );

        $this->assertTrue($plugin->ensureFreshToken());
        $this->assertSame(0, $api->refreshCalls);
    }

    // --- B6: pause/resume scrobble handlers ---------------------------------

    /**
     * onPlaybackPaused calls scrobblePause on the API with correct parameters.
     */
    public function testOnPlaybackPausedSubmitsScrobblePause(): void
    {
        $api = new FakeTraktApi();

        $plugin = $this->makeWiredPlugin(
            api: $api,
            repo: new RecordingSettingsRepository(),
            settings: $this->freshSettings(),
        );

        $plugin->onPlaybackPaused($this->pauseEvent());

        $this->assertSame(1, $api->scrobblePauseCalls);
        $this->assertSame(0, $api->scrobbleStartCalls);
        $this->assertSame(0, $api->scrobbleStopCalls);
    }

    /**
     * onPlaybackPaused does nothing when scrobble is disabled.
     */
    public function testOnPlaybackPausedDoesNothingWhenScrobbleDisabled(): void
    {
        $api = new FakeTraktApi();

        $settings = $this->freshSettings();
        $plugin = $this->makeWiredPlugin(
            api: $api,
            repo: new RecordingSettingsRepository(),
            settings: $settings,
        );
        $plugin->configure(array_merge($settings->toArray(), [
            'enabled' => true,
            'scrobble_enabled' => false,
        ]));

        $plugin->onPlaybackPaused($this->pauseEvent());

        $this->assertSame(0, $api->scrobblePauseCalls);
    }

    /**
     * onPlaybackResumed calls scrobbleStart on the API (Trakt uses start to resume).
     */
    public function testOnPlaybackResumedSubmitsScrobbleStart(): void
    {
        $api = new FakeTraktApi();

        $plugin = $this->makeWiredPlugin(
            api: $api,
            repo: new RecordingSettingsRepository(),
            settings: $this->freshSettings(),
        );

        $plugin->onPlaybackResumed($this->resumeEvent());

        $this->assertSame(1, $api->scrobbleStartCalls);
        $this->assertSame(0, $api->scrobblePauseCalls);
        $this->assertSame(0, $api->scrobbleStopCalls);
    }

    /**
     * onPlaybackResumed does nothing when scrobble is disabled.
     */
    public function testOnPlaybackResumedDoesNothingWhenScrobbleDisabled(): void
    {
        $api = new FakeTraktApi();

        $settings = $this->freshSettings();
        $plugin = $this->makeWiredPlugin(
            api: $api,
            repo: new RecordingSettingsRepository(),
            settings: $settings,
        );
        $plugin->configure(array_merge($settings->toArray(), [
            'enabled' => true,
            'scrobble_enabled' => false,
        ]));

        $plugin->onPlaybackResumed($this->resumeEvent());

        $this->assertSame(0, $api->scrobbleStartCalls);
    }

    // --- B4: single-flight lock around token refresh ------------------------

    /**
     * Single-flight guard: while a refresh is in flight, a SECOND caller that
     * re-enters ensureFreshToken() must NOT trigger a second refresh of the
     * SAME (now-rotating) refresh token. Trakt rotates the refresh token on
     * every exchange, so a second exchange would invalidate the account.
     *
     * The fake re-enters ensureFreshToken() from inside refreshAccessToken()
     * (i.e. while the gate is held), then returns the rotated tokens. We assert
     * the API's refresh endpoint was hit EXACTLY once across both callers.
     */
    public function testConcurrentRefreshTriggersExactlyOneExchange(): void
    {
        $api = new ReentrantRefreshApi();
        $api->refreshResult = [
            'access_token' => 'rotated-access',
            'refresh_token' => 'rotated-refresh',
            'expires_in' => 7200,
        ];

        $plugin = new TraktPlugin(
            $this->expiredSettings(),
            new NullLogger(),
            $api,
            new RecordingSettingsRepository(),
        );

        // Let the fake re-enter ensureFreshToken() on this same plugin while the
        // single-flight gate is held (simulating a second near-simultaneous
        // playback event arriving mid-refresh).
        $api->plugin = $plugin;

        $result = $plugin->ensureFreshToken();

        $this->assertTrue($result, 'the owning caller obtains a fresh token');
        $this->assertSame(
            1,
            $api->refreshCalls,
            'the refresh-token exchange must happen exactly once despite the concurrent caller',
        );
        $this->assertFalse(
            $api->reentrantRefreshedAgain,
            'the second (re-entrant) caller must NOT exchange the rotating refresh token again',
        );

        // The owning caller rotated the tokens.
        $settings = $plugin->getSettings();
        $this->assertSame('rotated-access', $settings->accessToken);
        $this->assertSame('rotated-refresh', $settings->refreshToken);
    }

    /**
     * The guard is released after a refresh (success path): a SUBSEQUENT,
     * later refresh can proceed. A wedged guard would block all future
     * refreshes after the first one.
     */
    public function testGuardIsReleasedSoLaterRefreshProceeds(): void
    {
        $api = new FakeTraktApi();
        $api->refreshResult = [
            'access_token' => 'first-access',
            'refresh_token' => 'first-refresh',
            'expires_in' => -10, // immediately re-expired so a 2nd refresh is needed
        ];

        $plugin = new TraktPlugin(
            $this->expiredSettings(),
            new NullLogger(),
            $api,
            new RecordingSettingsRepository(),
        );

        $this->assertTrue($plugin->ensureFreshToken());
        $this->assertSame(1, $api->refreshCalls);

        // The first refresh produced an already-expired token; the guard must
        // be free for the next refresh to run.
        $api->refreshResult = [
            'access_token' => 'second-access',
            'refresh_token' => 'second-refresh',
            'expires_in' => 7200,
        ];

        $this->assertTrue($plugin->ensureFreshToken());
        $this->assertSame(2, $api->refreshCalls, 'guard must be released so a later refresh proceeds');

        $settings = $plugin->getSettings();
        $this->assertSame('second-access', $settings->accessToken);
    }

    /**
     * The guard is released even when the refresh THROWS (try/finally): a
     * failed refresh must not wedge all future refreshes.
     */
    public function testGuardIsReleasedWhenRefreshThrows(): void
    {
        $api = new FakeTraktApi();
        $api->throwOnFirstRefresh = true; // first refresh blows up
        $api->refreshResult = [
            'access_token' => 'recovered-access',
            'refresh_token' => 'recovered-refresh',
            'expires_in' => 7200,
        ];

        $plugin = new TraktPlugin(
            $this->expiredSettings(),
            new NullLogger(),
            $api,
            new RecordingSettingsRepository(),
        );

        $this->assertFalse($plugin->ensureFreshToken(), 'a throwing refresh reports failure');
        $this->assertSame(1, $api->refreshCalls);

        // A later refresh must still be able to run (guard not wedged).
        $this->assertTrue($plugin->ensureFreshToken());
        $this->assertSame(2, $api->refreshCalls, 'guard released on the exception path');

        $settings = $plugin->getSettings();
        $this->assertSame('recovered-access', $settings->accessToken);
    }

    /**
     * End-to-end coroutine proof (requires ext-swoole): two coroutines call
     * ensureFreshToken() near-simultaneously on the SAME plugin. The first
     * acquires the single-flight gate and performs a refresh that yields; the
     * second blocks (yields) on the gate, then — via double-checked locking —
     * observes the rotated token and returns WITHOUT a second exchange.
     */
    public function testCoroutineSingleFlightExchangesOnce(): void
    {
        if (!\extension_loaded('swoole')) {
            $this->markTestSkipped('ext-swoole not available; covered by the re-entrant unit test');
        }

        $api = new YieldingRefreshApi();
        $api->refreshResult = [
            'access_token' => 'coro-access',
            'refresh_token' => 'coro-refresh',
            'expires_in' => 7200,
        ];

        $plugin = new TraktPlugin(
            $this->expiredSettings(),
            new NullLogger(),
            $api,
            new RecordingSettingsRepository(),
        );

        /** @var array<int, bool> $results */
        $results = [];

        \Swoole\Coroutine\run(static function () use ($plugin, &$results): void {
            \Swoole\Coroutine\go(static function () use ($plugin, &$results): void {
                $results['a'] = $plugin->ensureFreshToken();
            });
            \Swoole\Coroutine\go(static function () use ($plugin, &$results): void {
                $results['b'] = $plugin->ensureFreshToken();
            });
        });

        $this->assertTrue($results['a'] ?? false, 'coroutine A obtains a fresh token');
        $this->assertTrue($results['b'] ?? false, 'coroutine B obtains the rotated token via double-check');
        $this->assertSame(1, $api->refreshCalls, 'exactly one /oauth/token exchange across both coroutines');

        $settings = $plugin->getSettings();
        $this->assertSame('coro-access', $settings->accessToken);
        $this->assertSame('coro-refresh', $settings->refreshToken);
    }

    /**
     * The 401-recovery path must run THROUGH the single-flight rotation gate
     * (review trakt__secaudit Major-1 / trakt__srphp M4: refreshAfterAuthFailure()
     * called the exchange directly, so the account-invalidation race reopened
     * exactly where Trakt rotates the refresh token). The fake's first exchange
     * re-enters the plugin with a SECOND stop event — mid-exchange, while the
     * gate is held — whose scrobble also 401s. The re-entrant caller must not
     * POST the same refresh token a second time.
     */
    public function testConcurrent401RecoveryTriggersExactlyOneExchange(): void
    {
        $api = new ReentrantAuth401Api();
        $api->refreshResult = [
            'access_token' => 'rotated-access',
            'refresh_token' => 'rotated-refresh',
            'expires_in' => 7200,
        ];
        $repo = new RecordingSettingsRepository();
        $settings = $this->freshSettings();

        $plugin = new TraktPlugin($settings, new NullLogger(), $api, $repo);
        $container = new StubContainer([
            \Phlix\Media\Library\ItemRepository::class => new FakeItemRepository(),
            \Phlix\Auth\WatchHistory::class => null,
        ]);
        $plugin->onEnable($container);
        $plugin->configure(array_merge($settings->toArray(), ['enabled' => true]));

        $api->plugin = $plugin;

        $plugin->onPlaybackStopped($this->stopEvent());

        $this->assertSame(
            1,
            $api->refreshCalls,
            'the /oauth/token exchange behind a 401 recovery must happen exactly once',
        );
        $this->assertFalse(
            $api->reentrantExchange,
            'a 401 recovery arriving while the gate is held must NOT exchange the rotating refresh token again',
        );
        // outer 401 + re-entrant 401 + the owner's single retry.
        $this->assertSame(3, $api->scrobbleStopCalls);

        $rotated = $plugin->getSettings();
        $this->assertSame('rotated-access', $rotated->accessToken);
        $this->assertSame('rotated-refresh', $rotated->refreshToken);
        $this->assertNotEmpty($repo->saved, 'the rotated pair is persisted once');
    }

    /**
     * Two coroutines whose scrobbles both 401 while one gate-owned refresh is
     * mid-exchange: the awaiter must observe the rotation (double-checked
     * locking) and retry WITHOUT a second exchange. ext-swoole only.
     */
    public function testCoroutineConcurrent401RecoveriesExchangeOnce(): void
    {
        if (!\extension_loaded('swoole')) {
            $this->markTestSkipped('ext-swoole not available; covered by the re-entrant 401 unit test');
        }

        $api = new YieldingAuth401Api();
        $repo = new RecordingSettingsRepository();
        $settings = $this->freshSettings();

        $plugin = new TraktPlugin($settings, new NullLogger(), $api, $repo);
        $container = new StubContainer([
            \Phlix\Media\Library\ItemRepository::class => new FakeItemRepository(),
            \Phlix\Auth\WatchHistory::class => null,
        ]);
        $plugin->onEnable($container);
        $plugin->configure(array_merge($settings->toArray(), ['enabled' => true]));

        \Swoole\Coroutine\run(static function () use ($plugin): void {
            \Swoole\Coroutine\go(static function () use ($plugin): void {
                $plugin->onPlaybackStopped(new PlaybackStopped('sess-a', 'user-1', 'mi-1', 'dev-1', 1000 * \Phlix\Auth\WatchHistory::TICKS_PER_SECOND, false));
            });
            \Swoole\Coroutine\go(static function () use ($plugin): void {
                $plugin->onPlaybackStopped(new PlaybackStopped('sess-b', 'user-1', 'mi-1', 'dev-1', 1000 * \Phlix\Auth\WatchHistory::TICKS_PER_SECOND, false));
            });
        });

        $this->assertSame(1, $api->refreshCalls, 'exactly one /oauth/token exchange across both coroutines');
        // two first-pass 401s + two retries with the rotated token.
        $this->assertSame(4, $api->scrobbleStopCalls);
        $this->assertSame('rotated-access', $plugin->getSettings()->accessToken);
    }

    // --- S1: encrypt tokens at rest on the persist path ---------------------

    /**
     * When a cipher is wired, a token refresh persists CIPHERTEXT for the
     * access/refresh tokens (never the raw rotated values) and the in-memory
     * settings still hold the decrypted tokens for subsequent scrobbles.
     */
    public function testPersistedTokensAreEncryptedAtRest(): void
    {
        $repo = new RecordingSettingsRepository();
        $api = new FakeTraktApi();
        $api->refreshResult = [
            'access_token' => 'rotated-access',
            'refresh_token' => 'rotated-refresh',
            'expires_in' => 7200,
        ];
        $cipher = new RecordingCipher();

        $plugin = $this->makeWiredPlugin(
            api: $api,
            repo: $repo,
            settings: $this->expiredSettings(),
            cipher: $cipher,
        );

        $plugin->onPlaybackStopped($this->stopEvent());

        $this->assertSame(1, $api->refreshCalls);
        $this->assertNotEmpty($repo->saved, 'persistence writer must be invoked');

        $last = $repo->saved[count($repo->saved) - 1];

        // The STORED payload must be ciphertext, not the raw rotated tokens.
        $this->assertNotSame('rotated-access', $last['access_token']);
        $this->assertNotSame('rotated-refresh', $last['refresh_token']);
        $this->assertStringStartsWith('ENC(', (string) $last['access_token']);
        $this->assertStringStartsWith('ENC(', (string) $last['refresh_token']);
        $this->assertStringNotContainsString('rotated-access', (string) $last['access_token']);

        // The cipher actually encrypted both token fields.
        $this->assertContains('rotated-access', $cipher->encrypted);
        $this->assertContains('rotated-refresh', $cipher->encrypted);

        // In-memory settings keep the decrypted token for the next scrobble.
        $this->assertSame('rotated-access', $plugin->getSettings()->accessToken);

        // And the stored ciphertext round-trips back through the cipher.
        $this->assertSame('rotated-access', $cipher->decrypt((string) $last['access_token']));
    }

    /**
     * Graceful degrade (S1): with NO cipher available the persist path stores
     * the tokens as-is and does not crash; the suite's other tests already cover
     * the rotation, this asserts the no-cipher storage is plaintext (and safe).
     */
    public function testPersistWithoutCipherStoresPlaintextWithoutCrashing(): void
    {
        $repo = new RecordingSettingsRepository();
        $api = new FakeTraktApi();
        $api->refreshResult = [
            'access_token' => 'rotated-access',
            'refresh_token' => 'rotated-refresh',
            'expires_in' => 7200,
        ];

        // No cipher injected.
        $plugin = $this->makeWiredPlugin(
            api: $api,
            repo: $repo,
            settings: $this->expiredSettings(),
            cipher: null,
        );

        $plugin->onPlaybackStopped($this->stopEvent());

        $this->assertSame(1, $api->refreshCalls);
        $this->assertNotEmpty($repo->saved);
        $last = $repo->saved[count($repo->saved) - 1];

        // Degrade: stored as-is (the host logs a warning separately).
        $this->assertSame('rotated-access', $last['access_token']);
        $this->assertSame('rotated-refresh', $last['refresh_token']);
    }

    /**
     * The plugin's SPA projection never exposes the raw OAuth tokens.
     */
    public function testGetSettingsForSpaRedactsTokens(): void
    {
        $plugin = new TraktPlugin(
            new TraktSettings(
                accessToken: 'spa-secret-access',
                refreshToken: 'spa-secret-refresh',
                expiresAt: 1700000000,
                username: 'carol',
            ),
            new NullLogger(),
        );

        $spa = $plugin->getSettingsForSpa();

        $serialized = json_encode($spa);
        $this->assertIsString($serialized);
        $this->assertStringNotContainsString('spa-secret-access', $serialized);
        $this->assertStringNotContainsString('spa-secret-refresh', $serialized);
        $this->assertArrayNotHasKey('access_token', $spa);
        $this->assertArrayNotHasKey('refresh_token', $spa);
        $this->assertTrue($spa['has_tokens']);
        $this->assertSame('carol', $spa['username']);
    }

    /**
     * PG-143: the flag follows the plugin's actual cipher posture — an
     * enabled plugin holding a TokenCipher reports true, one without a
     * usable key reports false rather than leaving the portal guessing.
     */
    public function testGetSettingsForSpaAdvertisesCipherPosture(): void
    {
        $unciphered = new TraktPlugin(new TraktSettings(), new NullLogger());
        $this->assertFalse($unciphered->getSettingsForSpa()['token_encrypted']);

        $ciphered = new TraktPlugin(
            settings: new TraktSettings(),
            logger: new NullLogger(),
            tokenCipher: new SodiumTokenCipher(str_repeat("\x0f", SODIUM_CRYPTO_SECRETBOX_KEYBYTES)),
        );
        $this->assertTrue($ciphered->getSettingsForSpa()['token_encrypted']);
    }

    // --- A3: scrobble progress is a percentage (0-100), not raw seconds -----

    /**
     * A scrobble must report `progress` as a PERCENTAGE of the media duration,
     * derived from position + duration — NOT the raw position in seconds.
     *
     * The item is 200s long and the event position is 100s (half), so the
     * expected progress is 50.0%. The historic bug sent 100 (the raw seconds),
     * so this test discriminates the fix from the defect.
     */
    public function testScrobbleReportsProgressAsPercentageNotSeconds(): void
    {
        $api = new FakeTraktApi();
        $settings = $this->freshSettings();

        $plugin = new TraktPlugin($settings, new NullLogger(), $api, new RecordingSettingsRepository());
        $container = new StubContainer([
            \Phlix\Media\Library\ItemRepository::class => new DurationItemRepository(200),
            \Phlix\Auth\WatchHistory::class => null,
        ]);
        $plugin->onEnable($container);
        $plugin->configure(array_merge($settings->toArray(), ['enabled' => true]));

        // 100 seconds into a 200-second movie → 50%.
        $plugin->onPlaybackStarted(new PlaybackStarted(
            sessionId: 'sess-1',
            userId: 'user-1',
            mediaItemId: 'mi-1',
            deviceId: 'dev-1',
            positionTicks: 100 * \Phlix\Auth\WatchHistory::TICKS_PER_SECOND,
        ));

        $this->assertSame(1, $api->scrobbleStartCalls);
        $this->assertSame(50.0, $api->lastProgress, 'progress must be the percent, not the raw 100 seconds');
    }

    /**
     * A position beyond the media duration must clamp the reported progress to
     * 100% (Trakt rejects anything above 100), never overflow.
     */
    public function testScrobbleProgressClampsToOneHundred(): void
    {
        $api = new FakeTraktApi();
        $settings = $this->freshSettings();

        $plugin = new TraktPlugin($settings, new NullLogger(), $api, new RecordingSettingsRepository());
        $container = new StubContainer([
            \Phlix\Media\Library\ItemRepository::class => new DurationItemRepository(100),
            \Phlix\Auth\WatchHistory::class => null,
        ]);
        $plugin->onEnable($container);
        $plugin->configure(array_merge($settings->toArray(), ['enabled' => true]));

        // 500 seconds into a 100-second movie → clamped to 100%.
        $plugin->onPlaybackStopped(new PlaybackStopped(
            sessionId: 'sess-1',
            userId: 'user-1',
            mediaItemId: 'mi-1',
            deviceId: 'dev-1',
            finalPositionTicks: 500 * \Phlix\Auth\WatchHistory::TICKS_PER_SECOND,
            reachedEnd: false,
        ));

        $this->assertSame(1, $api->scrobbleStopCalls);
        $this->assertSame(100.0, $api->lastProgress);
    }

    /**
     * When the media carries no usable duration, progress falls back to 0.0
     * (a safe in-range value) rather than a bogus raw-seconds figure.
     */
    public function testScrobbleProgressIsZeroWhenDurationUnknown(): void
    {
        $api = new FakeTraktApi();
        $settings = $this->freshSettings();

        // FakeItemRepository returns a movie row WITHOUT any duration metadata.
        $plugin = $this->makeWiredPlugin(
            api: $api,
            repo: new RecordingSettingsRepository(),
            settings: $settings,
        );

        $plugin->onPlaybackPaused(new PlaybackPaused(
            sessionId: 'sess-1',
            userId: 'user-1',
            mediaItemId: 'mi-1',
            deviceId: 'dev-1',
            positionTicks: 90 * \Phlix\Auth\WatchHistory::TICKS_PER_SECOND,
        ));

        $this->assertSame(1, $api->scrobblePauseCalls);
        $this->assertSame(0.0, $api->lastProgress);
    }

    // --- A2: on-demand Trakt→Phlix pull entry point -------------------------

    /**
     * syncHistoryFromTrakt() must delegate to TraktHistorySync::syncTraktToPhlix
     * for the requested profile and return its integer result verbatim.
     */
    public function testSyncHistoryFromTraktDelegatesAndReturnsWrittenCount(): void
    {
        $api = new FakeTraktApi();
        $settings = $this->freshSettings();

        $seam = new RecordingHistorySync(
            $api,
            new \Phlix\Auth\WatchHistory(),
            $settings,
            new \Workerman\MySQL\TestableConnection(),
            new NullLogger(),
        );
        $seam->returnValue = 7;

        $plugin = new SeamTraktPlugin($settings, new NullLogger(), $api, new RecordingSettingsRepository());
        $plugin->historySyncSeam = $seam;
        $plugin->configure(array_merge($settings->toArray(), ['enabled' => true]));

        $container = new StubContainer([
            \Phlix\Media\Library\ItemRepository::class => new FakeItemRepository(),
            \Phlix\Auth\WatchHistory::class => new \Phlix\Auth\WatchHistory(),
            \Workerman\MySQL\Connection::class => new \Workerman\MySQL\TestableConnection(),
        ]);

        $written = $plugin->syncHistoryFromTrakt($container, 'profile-x');

        $this->assertSame(7, $written, 'must return syncTraktToPhlix result');
        $this->assertSame('profile-x', $seam->lastProfileId, 'must delegate the requested profile');
    }

    /**
     * syncHistoryFromTrakt() returns 0 (and does NOT delegate) when two-way
     * sync is disabled — the timer tick must be a cheap no-op in that case.
     */
    public function testSyncHistoryFromTraktReturnsZeroWhenSyncDisabled(): void
    {
        $api = new FakeTraktApi();
        $settings = new TraktSettings(
            accessToken: 'fresh-access',
            refreshToken: 'fresh-refresh',
            expiresAt: time() + 3600,
            syncEnabled: false,
            username: 'testuser',
        );

        $seam = new RecordingHistorySync(
            $api,
            new \Phlix\Auth\WatchHistory(),
            $settings,
            new \Workerman\MySQL\TestableConnection(),
            new NullLogger(),
        );
        $seam->returnValue = 99;

        $plugin = new SeamTraktPlugin($settings, new NullLogger(), $api, new RecordingSettingsRepository());
        $plugin->historySyncSeam = $seam;
        $plugin->configure(array_merge($settings->toArray(), ['enabled' => true, 'sync_enabled' => false]));

        $container = new StubContainer([
            \Phlix\Media\Library\ItemRepository::class => new FakeItemRepository(),
            \Phlix\Auth\WatchHistory::class => new \Phlix\Auth\WatchHistory(),
            \Workerman\MySQL\Connection::class => new \Workerman\MySQL\TestableConnection(),
        ]);

        $written = $plugin->syncHistoryFromTrakt($container);

        $this->assertSame(0, $written);
        $this->assertSame('', $seam->lastProfileId, 'must not delegate when sync is disabled');
    }

    // --- pull-path token lifecycle (M-TRAKT-07 / M-TRAKT-11) ----------------

    /**
     * A scheduled tick with an EXPIRED token must rotate it via the
     * single-flight gate BEFORE pulling (review trakt__secaudit Major-2 /
     * trakt__srphp M6: the pull path never refreshed at all — once the ~3-month
     * access token expired, history pull died silently forever, and with
     * scrobble_enabled off nothing ever called ensureFreshToken()).
     */
    public function testScheduledPullRefreshesExpiredTokenBeforeFetching(): void
    {
        $api = new FakeTraktApi();
        $api->refreshResult = [
            'access_token' => 'rotated-access',
            'refresh_token' => 'rotated-refresh',
            'expires_in' => 7200,
        ];
        $settings = $this->expiredSettings();

        $seam = new RecordingHistorySync(
            $api,
            new \Phlix\Auth\WatchHistory(),
            $settings,
            new \Workerman\MySQL\TestableConnection(),
            new NullLogger(),
        );

        $plugin = new SeamTraktPlugin($settings, new NullLogger(), $api, new RecordingSettingsRepository());
        $plugin->configure(array_merge($settings->toArray(), ['enabled' => true]));
        $plugin->historySyncSeam = $seam;

        $ref = new \ReflectionClass($plugin);
        $watchHistProp = $ref->getParentClass()->getProperty('watchHistory');
        $watchHistProp->setAccessible(true);
        $watchHistProp->setValue($plugin, new \Phlix\Auth\WatchHistory());
        $dbProp = $ref->getParentClass()->getProperty('db');
        $dbProp->setAccessible(true);
        $dbProp->setValue($plugin, new \Workerman\MySQL\TestableConnection());

        $method = $ref->getMethod('runScheduledSync');
        $method->setAccessible(true);
        $method->invoke($plugin);

        $this->assertSame(1, $api->refreshCalls, 'an expired token must be rotated proactively before the pull');
        $this->assertSame(1, $seam->pullCalls, 'the tick must still pull after the successful rotation');
        $this->assertSame('default', $seam->lastProfileId);
    }

    /**
     * When the proactive rotation cannot succeed, the tick must skip the pull
     * (no doomed HTTP round-trip, silent-death warning logged) instead of
     * grinding every subsequent tick against a dead credential.
     */
    public function testScheduledPullSkipsTickWhenRefreshImpossible(): void
    {
        $api = new FakeTraktApi();
        $api->throwOnFirstRefresh = true;
        $settings = $this->expiredSettings();

        $seam = new RecordingHistorySync(
            $api,
            new \Phlix\Auth\WatchHistory(),
            $settings,
            new \Workerman\MySQL\TestableConnection(),
            new NullLogger(),
        );

        $plugin = new SeamTraktPlugin($settings, new NullLogger(), $api, new RecordingSettingsRepository());
        $plugin->configure(array_merge($settings->toArray(), ['enabled' => true]));
        $plugin->historySyncSeam = $seam;

        $ref = new \ReflectionClass($plugin);
        $watchHistProp = $ref->getParentClass()->getProperty('watchHistory');
        $watchHistProp->setAccessible(true);
        $watchHistProp->setValue($plugin, new \Phlix\Auth\WatchHistory());
        $dbProp = $ref->getParentClass()->getProperty('db');
        $dbProp->setAccessible(true);
        $dbProp->setValue($plugin, new \Workerman\MySQL\TestableConnection());

        $method = $ref->getMethod('runScheduledSync');
        $method->setAccessible(true);
        $method->invoke($plugin);

        $this->assertSame(1, $api->refreshCalls, 'the refresh attempt is made once');
        $this->assertSame(0, $seam->pullCalls, 'a failed rotation must skip the doomed pull');
        $this->assertSame('', $seam->lastProfileId);
    }

    /**
     * The host-driven on-demand entry point obeys the same law as the tick:
     * expired token first rotates through the gate, then pulls.
     */
    public function testOnDemandPullRefreshesExpiredTokenBeforeFetching(): void
    {
        $api = new FakeTraktApi();
        $api->refreshResult = [
            'access_token' => 'rotated-access',
            'refresh_token' => 'rotated-refresh',
            'expires_in' => 7200,
        ];
        $settings = $this->expiredSettings();

        $seam = new RecordingHistorySync(
            $api,
            new \Phlix\Auth\WatchHistory(),
            $settings,
            new \Workerman\MySQL\TestableConnection(),
            new NullLogger(),
        );

        $plugin = new SeamTraktPlugin($settings, new NullLogger(), $api, new RecordingSettingsRepository());
        $plugin->configure(array_merge($settings->toArray(), ['enabled' => true]));
        $plugin->historySyncSeam = $seam;

        $container = new StubContainer([
            \Phlix\Media\Library\ItemRepository::class => new FakeItemRepository(),
            \Phlix\Auth\WatchHistory::class => new \Phlix\Auth\WatchHistory(),
            \Workerman\MySQL\Connection::class => new \Workerman\MySQL\TestableConnection(),
        ]);

        $written = $plugin->syncHistoryFromTrakt($container, 'profile-x');

        $this->assertSame(0, $written, 'the seam returns its configured count (0)');
        $this->assertSame(1, $api->refreshCalls);
        $this->assertSame(1, $seam->pullCalls);
        $this->assertSame('profile-x', $seam->lastProfileId);
    }

    /**
     * An authentication error raised INSIDE the pull is treated as "one gated
     * refresh then ONE re-pull" (review trakt__secaudit Major-2 cure). Before
     * the fix the 401 was swallowed by the sync layer's parent catch — one
     * generic warning line — so a locally-unexpired-but-server-rejected token
     * meant permanent silent sync death until manual re-auth.
     */
    public function testOnDemandPullRecoversWithOneRefreshAndOneRePull(): void
    {
        $api = new FakeTraktApi();
        $api->refreshResult = [
            'access_token' => 'rotated-access',
            'refresh_token' => 'rotated-refresh',
            'expires_in' => 7200,
        ];
        $settings = $this->freshSettings();

        $seam = new RecordingHistorySync(
            $api,
            new \Phlix\Auth\WatchHistory(),
            $settings,
            new \Workerman\MySQL\TestableConnection(),
            new NullLogger(),
        );
        $seam->throwAuthOnce = true;
        $seam->returnValue = 2;

        $plugin = new SeamTraktPlugin($settings, new NullLogger(), $api, new RecordingSettingsRepository());
        $plugin->configure(array_merge($settings->toArray(), ['enabled' => true]));
        $plugin->historySyncSeam = $seam;

        $container = new StubContainer([
            \Phlix\Media\Library\ItemRepository::class => new FakeItemRepository(),
            \Phlix\Auth\WatchHistory::class => new \Phlix\Auth\WatchHistory(),
            \Workerman\MySQL\Connection::class => new \Workerman\MySQL\TestableConnection(),
        ]);

        $written = $plugin->syncHistoryFromTrakt($container, 'profile-x');

        $this->assertSame(2, $written, 'the re-pull result reaches the caller');
        $this->assertSame(1, $api->refreshCalls, 'exactly one gated refresh after the 401');
        $this->assertSame(2, $seam->pullCalls, 'one pull, one re-pull — never a retry loop');
        $this->assertSame('rotated-access', $plugin->getSettings()->accessToken);
    }

    /**
     * When the reactive refresh itself fails, the entry point reports 0 and
     * does not re-pull (the scrobble-side warning already names the cause);
     * no exception escapes to the host and no second pull is attempted.
     */
    public function testOnDemandPullGivesUpCleanlyWhenReactiveRefreshFails(): void
    {
        $api = new FakeTraktApi();
        $api->throwOnFirstRefresh = true;
        $settings = $this->freshSettings();

        $seam = new RecordingHistorySync(
            $api,
            new \Phlix\Auth\WatchHistory(),
            $settings,
            new \Workerman\MySQL\TestableConnection(),
            new NullLogger(),
        );
        $seam->throwAuthOnce = true;

        $plugin = new SeamTraktPlugin($settings, new NullLogger(), $api, new RecordingSettingsRepository());
        $plugin->configure(array_merge($settings->toArray(), ['enabled' => true]));
        $plugin->historySyncSeam = $seam;

        $container = new StubContainer([
            \Phlix\Media\Library\ItemRepository::class => new FakeItemRepository(),
            \Phlix\Auth\WatchHistory::class => new \Phlix\Auth\WatchHistory(),
            \Workerman\MySQL\Connection::class => new \Workerman\MySQL\TestableConnection(),
        ]);

        $written = $plugin->syncHistoryFromTrakt($container);

        $this->assertSame(0, $written);
        $this->assertSame(1, $api->refreshCalls);
        $this->assertSame(1, $seam->pullCalls, 'a failed reactive refresh must not re-pull');
    }

    // --- helpers ------------------------------------------------------------

    private function makeWiredPlugin(
        FakeTraktApi $api,
        ?RecordingSettingsRepository $repo,
        TraktSettings $settings,
        ?TokenCipher $cipher = null,
    ): TraktPlugin {
        $plugin = new TraktPlugin($settings, new NullLogger(), $api, $repo, $cipher);

        // Wire enabled + a resolvable media item via a stub container, without
        // overwriting the injected API/repository or the supplied settings.
        $container = new StubContainer([
            \Phlix\Media\Library\ItemRepository::class => new FakeItemRepository(),
            \Phlix\Auth\WatchHistory::class => null,
        ]);
        $plugin->onEnable($container);

        // configure() resets settings from an array; re-apply our settings via
        // the array path so `enabled` is set AND expiry/tokens are preserved.
        $plugin->configure(array_merge($settings->toArray(), ['enabled' => true]));

        return $plugin;
    }

    private function freshSettings(): TraktSettings
    {
        return new TraktSettings(
            accessToken: 'fresh-access',
            refreshToken: 'fresh-refresh',
            expiresAt: time() + 3600,
            username: 'testuser',
        );
    }

    private function expiredSettings(): TraktSettings
    {
        return new TraktSettings(
            accessToken: 'old-access',
            refreshToken: 'old-refresh',
            expiresAt: time() - 3600,
            username: 'testuser',
        );
    }

    private function startEvent(): PlaybackStarted
    {
        return new PlaybackStarted(
            sessionId: 'sess-1',
            userId: 'user-1',
            mediaItemId: 'mi-1',
            deviceId: 'dev-1',
            positionTicks: 0,
        );
    }

    private function stopEvent(): PlaybackStopped
    {
        return new PlaybackStopped(
            sessionId: 'sess-1',
            userId: 'user-1',
            mediaItemId: 'mi-1',
            deviceId: 'dev-1',
            finalPositionTicks: 1000 * \Phlix\Auth\WatchHistory::TICKS_PER_SECOND,
            reachedEnd: false,
        );
    }

    private function pauseEvent(): PlaybackPaused
    {
        return new PlaybackPaused(
            sessionId: 'sess-1',
            userId: 'user-1',
            mediaItemId: 'mi-1',
            deviceId: 'dev-1',
            positionTicks: 5_000_000,
        );
    }

    private function resumeEvent(): PlaybackResumed
    {
        return new PlaybackResumed(
            sessionId: 'sess-1',
            userId: 'user-1',
            mediaItemId: 'mi-1',
            deviceId: 'dev-1',
            positionTicks: 5_000_000,
        );
    }

    /**
     * PG-128 end-to-end: the operator config read at the composition root
     * (initApi) is THE source for the OAuth redirect URI — the API client it
     * builds must carry the configured value verbatim, not a value re-read
     * through a second raw include inside TraktApi.
     */
    public function testInitApiFeedsTheOperatorRedirectUriToTheApiClient(): void
    {
        $plugin = new TraktPlugin(null, new CapturingLogger());
        $config = [
            'client_id' => 'cid-1',
            'client_secret' => 'sec-1',
            'redirect_uri' => 'https://cfg.example/api/v1/oauth/trakt/callback',
        ];

        (new \ReflectionMethod(TraktPlugin::class, 'initApi'))->invoke($plugin, $config);

        $api = (new \ReflectionProperty(TraktPlugin::class, 'api'))->getValue($plugin);
        $this->assertNotNull($api, 'operator credentials must build the API client');

        $authUrl = $api->getAuthUrl('state-x', 'verifier-y');
        $this->assertStringContainsString(rawurlencode($config['redirect_uri']), $authUrl);
        $this->assertStringNotContainsString('localhost', $authUrl);
    }

    /**
     * PG-128 fail-loud: with an empty operator config initApi() used to
     * decline in total silence; the missing keys must be named.
     */
    public function testInitApiWarnsNamingMissingOperatorCredentials(): void
    {
        $logger = new CapturingLogger();
        $plugin = new TraktPlugin(null, $logger);

        (new \ReflectionMethod(TraktPlugin::class, 'initApi'))->invoke($plugin, []);

        $this->assertNull((new \ReflectionProperty(TraktPlugin::class, 'api'))->getValue($plugin));
        $joined = implode("\n", $logger->warnings);
        $this->assertStringContainsString('client_id', $joined);
        $this->assertStringContainsString('client_secret', $joined);
    }

    /**
     * PG-128 fallback law: when the operator config carries no usable
     * redirect_uri the historic literal default stays byte-ident (law (d)),
     * but the fallback must now be announced, and an empty-string value is
     * normalized to "absent" (the shadow loader used to pass '' through).
     */
    public function testInitApiAnnouncesRedirectUriFallbackAndNormalizesEmpty(): void
    {
        $logger = new CapturingLogger();
        $plugin = new TraktPlugin(null, $logger);

        (new \ReflectionMethod(TraktPlugin::class, 'initApi'))->invoke($plugin, [
            'client_id' => 'cid-1',
            'client_secret' => 'sec-1',
            'redirect_uri' => '',
        ]);

        $api = (new \ReflectionProperty(TraktPlugin::class, 'api'))->getValue($plugin);
        $this->assertNotNull($api);

        $authUrl = $api->getAuthUrl('state-x', 'verifier-y');
        $this->assertStringContainsString(
            rawurlencode('https://localhost/api/v1/oauth/trakt/callback'),
            $authUrl,
            'the fallback literal must not drift'
        );
        $this->assertStringContainsString('redirect_uri', implode("\n", $logger->warnings));
    }

    // --- PG-132: dead credential must not schedule a doomed scrobble --------

    /**
     * All four playback handlers ran ensureFreshToken() and DISCARDED the
     * bool: when the token was expired AND the refresh failed, they still
     * scheduled the scrobble with the known-dead access token — a doomed
     * round-trip per event that additionally re-entered the 401 retry path.
     * The scheduled-sync entry (pullHistoryWithAuthRecovery) already skips
     * on false; the playback legs must honor the same answer.
     */
    public function testPlaybackStartSkipsScrobbleWhenCredentialCannotBeRecovered(): void
    {
        $api = new FakeTraktApi();
        $api->throwOnFirstRefresh = true;
        $plugin = $this->makeWiredPlugin($api, new RecordingSettingsRepository(), $this->expiredSettings());

        $plugin->onPlaybackStarted($this->startEvent());

        $this->assertSame(1, $api->refreshCalls, 'the refresh is attempted once');
        $this->assertSame(0, $api->scrobbleStartCalls, 'a known-dead credential must not scrobble');
    }

    public function testPlaybackPausedSkipsScrobbleWhenCredentialCannotBeRecovered(): void
    {
        $api = new FakeTraktApi();
        $api->throwOnFirstRefresh = true;
        $plugin = $this->makeWiredPlugin($api, new RecordingSettingsRepository(), $this->expiredSettings());

        $plugin->onPlaybackPaused($this->pauseEvent());

        $this->assertSame(1, $api->refreshCalls);
        $this->assertSame(0, $api->scrobblePauseCalls);
    }

    public function testPlaybackResumedSkipsScrobbleWhenCredentialCannotBeRecovered(): void
    {
        $api = new FakeTraktApi();
        $api->throwOnFirstRefresh = true;
        $plugin = $this->makeWiredPlugin($api, new RecordingSettingsRepository(), $this->expiredSettings());

        $plugin->onPlaybackResumed($this->resumeEvent());

        $this->assertSame(1, $api->refreshCalls);
        $this->assertSame(0, $api->scrobbleStartCalls, 'resume maps onto the start leg');
    }

    public function testPlaybackStoppedSkipsScrobbleWhenCredentialCannotBeRecovered(): void
    {
        $api = new FakeTraktApi();
        $api->throwOnFirstRefresh = true;
        $plugin = $this->makeWiredPlugin($api, new RecordingSettingsRepository(), $this->expiredSettings());

        $plugin->onPlaybackStopped($this->stopEvent());

        $this->assertSame(1, $api->refreshCalls);
        $this->assertSame(0, $api->scrobbleStopCalls);
    }

    // --- PG-135: completed-history push carries the pull's recovery law -----

    /**
     * The scheduled pull gets one gated refresh and one re-pull on a 401;
     * the event-driven push had no such path on the parent — syncPhlixToTrakt
     * swallowed the auth error into a silent false, so a revocation at stop
     * time lost the completion credit with no recovery ever attempted. A
     * fresh-credential plugin must rotate once and re-push exactly once.
     */
    public function testHistoryPushRecoversWithOneRefreshAndOneRePush(): void
    {
        $api = new PushRecoveryTraktApi();
        $plugin = $this->wirePushRecoveryPlugin($api);

        $plugin->onPlaybackStopped(new PlaybackStopped(
            sessionId: 'sess-1',
            userId: 'user-1',
            mediaItemId: 'mi-1',
            deviceId: 'dev-1',
            finalPositionTicks: 1000 * \Phlix\Auth\WatchHistory::TICKS_PER_SECOND,
            reachedEnd: true,
        ));

        $this->assertSame(1, $api->refreshCalls, 'exactly one gated refresh after the push 401');
        $this->assertSame(2, $api->addToHistoryCalls, 'the failed push must be replayed exactly once');
        $this->assertSame(
            'rotated-access',
            $api->lastPushToken,
            'the re-push must carry the rotated token, not the dead one'
        );
    }

    /**
     * When the credential cannot be recovered the push degrades LOUDLY and
     * once: no exception may escape the playback handler, and there is
     * exactly one doomed attempt — no retry loop against a dead token.
     */
    public function testHistoryPushDegradesLoudlyWhenCredentialCannotRecover(): void
    {
        $api = new PushRecoveryTraktApi();
        $api->failRefresh = true;
        $plugin = $this->wirePushRecoveryPlugin($api);

        $plugin->onPlaybackStopped(new PlaybackStopped(
            sessionId: 'sess-1',
            userId: 'user-1',
            mediaItemId: 'mi-1',
            deviceId: 'dev-1',
            finalPositionTicks: 1000 * \Phlix\Auth\WatchHistory::TICKS_PER_SECOND,
            reachedEnd: true,
        ));

        $this->assertSame(1, $api->refreshCalls, 'the recovery attempts one exchange');
        $this->assertSame(1, $api->addToHistoryCalls, 'an unrecoverable credential must not be re-grinded');
    }

    /**
     * Build a plugin wired for the reachedEnd push path: the event leg needs
     * a resolvable media item, and syncToTrakt additionally needs the
     * watch-history + DB collaborators that onEnable leaves null in the
     * stub container (set by reflection, same idiom as the pull tests).
     */
    private function wirePushRecoveryPlugin(PushRecoveryTraktApi $api): TraktPlugin
    {
        $settings = $this->freshSettings();
        $plugin = new TraktPlugin($settings, new NullLogger(), $api, new RecordingSettingsRepository());

        $container = new StubContainer([
            \Phlix\Media\Library\ItemRepository::class => new FakeItemRepository(),
            \Phlix\Auth\WatchHistory::class => null,
        ]);
        $plugin->onEnable($container);
        $plugin->configure(array_merge($settings->toArray(), ['enabled' => true]));

        $ref = new \ReflectionClass($plugin);
        $watchHistory = $this->createMock(\Phlix\Auth\WatchHistory::class);
        $watchHistory->method('getForMediaItem')->willReturn([
            'media_name' => 'Test Movie',
            'media_type' => 'movie',
            'metadata' => ['tmdb_id' => 42],
            'progress_percent' => 95.0,
            'last_watched_at' => '2024-06-15T12:00:00Z',
            'duration_ticks' => 1000 * \Phlix\Auth\WatchHistory::TICKS_PER_SECOND,
        ]);
        $watchProp = $ref->getProperty('watchHistory');
        $watchProp->setAccessible(true);
        $watchProp->setValue($plugin, $watchHistory);

        $dbProp = $ref->getProperty('db');
        $dbProp->setAccessible(true);
        $dbProp->setValue($plugin, new \Workerman\MySQL\TestableConnection());

        return $plugin;
    }
}

/**
 * API stand-in for the reachedEnd history push: the FIRST push answers 401
 * with the pre-refresh token and the rotated token succeeds; the refresh
 * itself is counted and can be made to fail. scrobbleStop stays benign so
 * the event leg does not interfere with the push accounting.
 *
 * @internal test seam
 */
final class PushRecoveryTraktApi extends TraktApi
{
    public int $refreshCalls = 0;
    public int $scrobbleStopCalls = 0;
    public int $addToHistoryCalls = 0;
    public string $lastPushToken = '';
    public bool $failRefresh = false;

    public function __construct()
    {
        parent::__construct(new NullHttpClient(), 'client-id', 'client-secret', new NullLogger());
    }

    public function refreshAccessToken(string $refreshToken): array
    {
        $this->refreshCalls++;

        if ($this->failRefresh) {
            throw new TraktApiException('refresh boom', 500);
        }

        return [
            'access_token' => 'rotated-access',
            'refresh_token' => 'rotated-refresh',
            'expires_in' => 3600,
        ];
    }

    public function scrobbleStop(\Phlix\Media\Library\MediaItem $item, float $progress, string $accessToken): array
    {
        $this->scrobbleStopCalls++;

        return ['action' => 'stop', 'watched_at' => '2026-06-28T00:00:00Z'];
    }

    public function addToHistory(
        \Phlix\Media\Library\MediaItem $item,
        \DateTimeImmutable $watchedAt,
        string $accessToken
    ): array {
        $this->addToHistoryCalls++;
        $this->lastPushToken = $accessToken;

        if ($accessToken !== 'rotated-access') {
            throw new TraktAuthenticationException('Expired token', 401);
        }

        return ['added' => ['movies' => 1]];
    }
}

/**
 * Fake TraktApi that records refresh/scrobble calls and can simulate a 401.
 */
final class FakeTraktApi extends TraktApi
{
    public int $refreshCalls = 0;
    public int $scrobbleStartCalls = 0;
    public int $scrobbleStopCalls = 0;
    public int $scrobblePauseCalls = 0;
    public bool $throwAuthOnFirstScrobble = false;
    public bool $throwOnFirstRefresh = false;
    public string $lastScrobbleToken = '';
    /** Progress value (percent) passed to the most recent scrobble call. */
    public float $lastProgress = -1.0;

    /** @var array<string, mixed> */
    public array $refreshResult = [
        'access_token' => 'rotated-access',
        'refresh_token' => 'rotated-refresh',
        'expires_in' => 3600,
    ];

    public function __construct()
    {
        parent::__construct(new NullHttpClient(), 'client-id', 'client-secret', new NullLogger());
    }

    public function refreshAccessToken(string $refreshToken): array
    {
        $this->refreshCalls++;

        if ($this->throwOnFirstRefresh && $this->refreshCalls === 1) {
            throw new TraktApiException('refresh boom', 500);
        }

        return $this->refreshResult;
    }

    public function scrobbleStart(\Phlix\Media\Library\MediaItem $item, float $progress, string $accessToken): array
    {
        $this->scrobbleStartCalls++;
        $this->lastScrobbleToken = $accessToken;
        $this->lastProgress = $progress;

        if ($this->throwAuthOnFirstScrobble && $this->scrobbleStartCalls === 1) {
            throw new TraktAuthenticationException('Unauthorized', 401);
        }

        return ['action' => 'start', 'watched_at' => '2026-06-28T00:00:00Z'];
    }

    public function scrobbleStop(\Phlix\Media\Library\MediaItem $item, float $progress, string $accessToken): array
    {
        $this->scrobbleStopCalls++;
        $this->lastScrobbleToken = $accessToken;
        $this->lastProgress = $progress;

        if ($this->throwAuthOnFirstScrobble && $this->scrobbleStopCalls === 1) {
            throw new TraktAuthenticationException('Unauthorized', 401);
        }

        return ['action' => 'stop', 'watched_at' => '2026-06-28T00:00:00Z'];
    }

    public function scrobblePause(\Phlix\Media\Library\MediaItem $item, float $progress, string $accessToken): array
    {
        $this->scrobblePauseCalls++;
        $this->lastScrobbleToken = $accessToken;
        $this->lastProgress = $progress;

        if ($this->throwAuthOnFirstScrobble && $this->scrobblePauseCalls === 1) {
            throw new TraktAuthenticationException('Unauthorized', 401);
        }

        return ['action' => 'pause', 'watched_at' => '2026-06-28T00:00:00Z'];
    }
}

/**
 * Fake whose refreshAccessToken() RE-ENTERS the plugin's ensureFreshToken()
 * once, while the single-flight gate is held, to simulate a second
 * near-simultaneous playback event arriving mid-refresh (no coroutine needed).
 * It records whether that re-entrant call performed a SECOND refresh.
 */
final class ReentrantRefreshApi extends TraktApi
{
    public int $refreshCalls = 0;
    public bool $reentrantRefreshedAgain = false;
    public ?TraktPlugin $plugin = null;
    private bool $reentered = false;

    /** @var array<string, mixed> */
    public array $refreshResult = [
        'access_token' => 'rotated-access',
        'refresh_token' => 'rotated-refresh',
        'expires_in' => 7200,
    ];

    public function __construct()
    {
        parent::__construct(new NullHttpClient(), 'client-id', 'client-secret', new NullLogger());
    }

    public function refreshAccessToken(string $refreshToken): array
    {
        $this->refreshCalls++;

        // On the FIRST exchange (gate held by the owner), re-enter once to
        // simulate the concurrent second caller. If the gate is sound, this
        // re-entrant ensureFreshToken() must NOT cause another exchange.
        if (!$this->reentered && $this->plugin !== null) {
            $this->reentered = true;
            $callsBefore = $this->refreshCalls;
            $this->plugin->ensureFreshToken();
            if ($this->refreshCalls > $callsBefore) {
                $this->reentrantRefreshedAgain = true;
            }
        }

        return $this->refreshResult;
    }
}

/**
 * Fake whose refreshAccessToken() YIELDS (Coroutine::sleep) so a second
 * coroutine can reach ensureFreshToken() and block on the single-flight gate
 * while this exchange is in flight. Requires ext-swoole.
 */
final class YieldingRefreshApi extends TraktApi
{
    public int $refreshCalls = 0;

    /** @var array<string, mixed> */
    public array $refreshResult = [
        'access_token' => 'rotated-access',
        'refresh_token' => 'rotated-refresh',
        'expires_in' => 7200,
    ];

    public function __construct()
    {
        parent::__construct(new NullHttpClient(), 'client-id', 'client-secret', new NullLogger());
    }

    public function refreshAccessToken(string $refreshToken): array
    {
        $this->refreshCalls++;

        // Yield so the sibling coroutine runs and parks on the gate before we
        // rotate. Cooperative — never a blocking sleep.
        if (\class_exists(\Swoole\Coroutine::class) && \Swoole\Coroutine::getCid() > 0) {
            \Swoole\Coroutine::sleep(0.02);
        }

        return $this->refreshResult;
    }
}

/**
 * Fake whose scrobbles reject any token but the rotated one (a true 401 until
 * rotation) and whose refreshAccessToken() re-enters the plugin with a second
 * PlaybackStopped while the exchange is in flight — the 401-recovery twin of
 * ReentrantRefreshApi. A sound gate refuses the re-entrant caller a second
 * exchange (review trakt__secaudit Major-1 / trakt__srphp M4).
 */
final class ReentrantAuth401Api extends TraktApi
{
    public int $refreshCalls = 0;
    public int $scrobbleStopCalls = 0;
    public bool $reentrantExchange = false;
    public ?TraktPlugin $plugin = null;
    private bool $reentered = false;

    /** @var array<string, mixed> */
    public array $refreshResult = [
        'access_token' => 'rotated-access',
        'refresh_token' => 'rotated-refresh',
        'expires_in' => 7200,
    ];

    public function __construct()
    {
        parent::__construct(new NullHttpClient(), 'client-id', 'client-secret', new NullLogger());
    }

    public function scrobbleStop(\Phlix\Media\Library\MediaItem $item, float $progress, string $accessToken): array
    {
        $this->scrobbleStopCalls++;

        if ($accessToken !== 'rotated-access') {
            throw new TraktAuthenticationException('Unauthorized', 401);
        }

        return ['action' => 'stop', 'watched_at' => '2026-10-30T00:00:00Z'];
    }

    public function refreshAccessToken(string $refreshToken): array
    {
        $this->refreshCalls++;

        if (!$this->reentered && $this->plugin !== null) {
            $this->reentered = true;
            $callsBefore = $this->refreshCalls;
            // Second playback stops mid-exchange → its scrobble 401s → its
            // recovery must be refused by the single-flight gate.
            $this->plugin->onPlaybackStopped(new PlaybackStopped(
                'sess-2',
                'user-1',
                'mi-1',
                'dev-1',
                1000 * \Phlix\Auth\WatchHistory::TICKS_PER_SECOND,
                false,
            ));
            if ($this->refreshCalls > $callsBefore) {
                $this->reentrantExchange = true;
            }
        }

        return $this->refreshResult;
    }
}

/**
 * Like ReentrantAuth401Api but yields (instead of re-entering) inside the
 * exchange, so two real coroutines can race the 401-recovery path. ext-swoole.
 */
final class YieldingAuth401Api extends TraktApi
{
    public int $refreshCalls = 0;
    public int $scrobbleStopCalls = 0;

    /** @var array<string, mixed> */
    public array $refreshResult = [
        'access_token' => 'rotated-access',
        'refresh_token' => 'rotated-refresh',
        'expires_in' => 7200,
    ];

    public function __construct()
    {
        parent::__construct(new NullHttpClient(), 'client-id', 'client-secret', new NullLogger());
    }

    public function scrobbleStop(\Phlix\Media\Library\MediaItem $item, float $progress, string $accessToken): array
    {
        $this->scrobbleStopCalls++;

        if ($accessToken !== 'rotated-access') {
            throw new TraktAuthenticationException('Unauthorized', 401);
        }

        return ['action' => 'stop', 'watched_at' => '2026-10-30T00:00:00Z'];
    }

    public function refreshAccessToken(string $refreshToken): array
    {
        $this->refreshCalls++;

        if (\class_exists(\Swoole\Coroutine::class) && \Swoole\Coroutine::getCid() > 0) {
            \Swoole\Coroutine::sleep(0.02);
        }

        return $this->refreshResult;
    }
}

/**
 * Captures every settings map passed to save().
 */
final class RecordingSettingsRepository implements TraktSettingsRepository
{
    /** @var array<int, array<string, mixed>> */
    public array $saved = [];

    public function save(array $settings): void
    {
        $this->saved[] = $settings;
    }
}

/**
 * Minimal PSR-11 container that returns pre-registered services.
 *
 * @internal test seam
 */
final class StubContainer implements \Psr\Container\ContainerInterface
{
    /** @param array<string, mixed> $services */
    public function __construct(private array $services = [])
    {
    }

    public function get(string $id): mixed
    {
        return $this->services[$id] ?? null;
    }

    public function has(string $id): bool
    {
        return array_key_exists($id, $this->services);
    }
}

/**
 * Reversible, recording fake cipher: wraps plaintext as ENC(<base64>) so a test
 * can prove the persisted value is ciphertext (transformed, not the raw token)
 * while still round-tripping. Records each plaintext it encrypts and passes
 * through any value not in its own wrapper format (legacy plaintext).
 *
 * @internal test seam
 */
final class RecordingCipher implements TokenCipher
{
    /** @var array<int, string> */
    public array $encrypted = [];

    public function encrypt(string $plain): string
    {
        $this->encrypted[] = $plain;

        return 'ENC(' . base64_encode($plain) . ')';
    }

    public function decrypt(string $cipher): string
    {
        if (!str_starts_with($cipher, 'ENC(') || !str_ends_with($cipher, ')')) {
            return $cipher;
        }

        $inner = substr($cipher, 4, -1);
        $decoded = base64_decode($inner, true);

        return $decoded === false ? $cipher : $decoded;
    }
}

/**
 * No-op HTTP client so the FakeTraktApi can satisfy the parent constructor.
 */
final class NullHttpClient implements HttpClientInterface
{
    public function get(string $url, array $params = [], array $headers = []): array
    {
        return [];
    }

    public function post(string $url, array $data = [], array $headers = []): array
    {
        return [];
    }
}

/**
 * Stub ItemRepository: findById() returns a fixed row so findMediaItem()
 * resolves to a MediaItem (via the canonical MediaItem stub's fromRow()).
 */
class FakeItemRepository
{
    /** @return array<string, mixed>|null */
    public function findById(string $id): ?array
    {
        return [
            'id' => $id,
            'name' => 'Test Movie',
            'type' => 'movie',
            'path' => '/movies/test.mkv',
            'metadata' => ['trakt_id' => 1, 'imdb_id' => 'tt0000001', 'tmdb_id' => 42],
        ];
    }
}

if (!class_exists(\Phlix\Media\Library\ItemRepository::class)) {
    \class_alias(FakeItemRepository::class, \Phlix\Media\Library\ItemRepository::class);
}

// MediaItemStub is also defined in TraktApiTest.php - only declare if not already loaded.
if (!class_exists(MediaItemStub::class)) {
    /**
     * Minimal stand-in for the host-supplied Phlix\Media\Library\MediaItem.
     *
     * The real class lives in the Phlix server and is not part of this plugin's
     * dependency closure, so tests register this stub under the canonical FQCN
     * when the real class is unavailable.
     */
    final class MediaItemStub
    {
        /**
         * @param array<string, mixed> $metadata
         */
        public function __construct(
            public string $id,
            public string $name,
            public string $type,
            public string $path,
            public array $metadata = [],
        ) {
        }

        /**
         * Build a stub MediaItem from a DB-style row.
         *
         * @param array<string, mixed> $row
         */
        public static function fromRow(array $row): self
        {
            $metadata = is_array($row['metadata'] ?? null) ? $row['metadata'] : [];

            return new self(
                id: is_string($row['id'] ?? null) ? $row['id'] : '',
                name: is_string($row['name'] ?? null) ? $row['name'] : '',
                type: is_string($row['type'] ?? null) ? $row['type'] : 'movie',
                path: is_string($row['path'] ?? null) ? $row['path'] : '',
                metadata: $metadata,
            );
        }
    }
}

if (!class_exists(\Phlix\Media\Library\MediaItem::class)) {
    \class_alias(MediaItemStub::class, \Phlix\Media\Library\MediaItem::class);
}

/**
 * ItemRepository stub whose findById() carries a duration (seconds) in the
 * media metadata, so progress-percent computation has a real denominator.
 */
final class DurationItemRepository extends FakeItemRepository
{
    public function __construct(private int $durationSeconds)
    {
    }

    /** @return array<string, mixed>|null */
    public function findById(string $id): ?array
    {
        return [
            'id' => $id,
            'name' => 'Test Movie',
            'type' => 'movie',
            'path' => '/movies/test.mkv',
            'metadata' => [
                'trakt_id' => 1,
                'imdb_id' => 'tt0000001',
                'tmdb_id' => 42,
                'duration_seconds' => $this->durationSeconds,
            ],
        ];
    }
}

/**
 * TraktPlugin subclass exposing a settable TraktHistorySync seam so the
 * on-demand pull entry point can be verified without a live DB/HTTP round-trip.
 */
final class SeamTraktPlugin extends TraktPlugin
{
    public ?TraktHistorySync $historySyncSeam = null;

    protected function makeHistorySync(): TraktHistorySync
    {
        /** @var TraktHistorySync $seam */
        $seam = $this->historySyncSeam;

        return $seam;
    }
}

/**
 * TraktHistorySync stub recording the profile it was asked to reconcile and
 * returning a configurable written-count, so delegation can be asserted.
 * `throwAuthOnce` simulates a 401 surfacing from inside the real pull.
 */
final class RecordingHistorySync extends TraktHistorySync
{
    public string $lastProfileId = '';
    public int $returnValue = 0;
    public int $pullCalls = 0;
    public bool $throwAuthOnce = false;

    public function syncTraktToPhlix(string $profileId): int
    {
        $this->lastProfileId = $profileId;
        $this->pullCalls++;

        if ($this->throwAuthOnce && $this->pullCalls === 1) {
            throw new TraktAuthenticationException('Unauthorized', 401);
        }

        return $this->returnValue;
    }
}

/**
 * PSR-3 logger that records warning messages for assertions (PG-128).
 *
 * Signature mirrors NullLogger's loose parameter typing so it stays
 * compatible across psr/log majors.
 */
final class CapturingLogger extends NullLogger
{
    /** @var list<string> */
    public array $warnings = [];

    public function warning($message, array $context = []): void
    {
        $this->warnings[] = (string) $message;
    }
}
