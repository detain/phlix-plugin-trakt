<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins\Scrobbler\Trakt;

use Phlix\Plugins\Scrobbler\Trakt\HttpClientInterface;
use Phlix\Plugins\Scrobbler\Trakt\TraktApi;
use Phlix\Plugins\Scrobbler\Trakt\TraktApiException;
use Phlix\Plugins\Scrobbler\Trakt\TraktAuthenticationException;
use Phlix\Plugins\Scrobbler\Trakt\TokenCipher;
use Phlix\Plugins\Scrobbler\Trakt\TraktPlugin;
use Phlix\Plugins\Scrobbler\Trakt\TraktSettings;
use Phlix\Plugins\Scrobbler\Trakt\TraktSettingsRepository;
use Phlix\Shared\Events\Playback\PlaybackPaused;
use Phlix\Shared\Events\Playback\PlaybackResumed;
use Phlix\Shared\Events\Playback\PlaybackStarted;
use Phlix\Shared\Events\Playback\PlaybackStopped;
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
     * (d) When no persistence writer is available, a refresh no-ops the
     * persist (warning only) and does NOT crash; tokens still rotate in memory.
     */
    public function testRefreshWithoutPersistenceWriterDoesNotCrash(): void
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

        $this->assertSame(1, $api->refreshCalls);
        $this->assertSame(1, $api->scrobbleStopCalls);

        $settings = $plugin->getSettings();
        $this->assertSame('rotated-access', $settings->accessToken);
        $this->assertSame('rotated-refresh', $settings->refreshToken);
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
            finalPositionTicks: 10_000_000,
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

    public function scrobbleStart(\Phlix\Media\Library\MediaItem $item, int $progress, string $accessToken): array
    {
        $this->scrobbleStartCalls++;
        $this->lastScrobbleToken = $accessToken;

        if ($this->throwAuthOnFirstScrobble && $this->scrobbleStartCalls === 1) {
            throw new TraktAuthenticationException('Unauthorized', 401);
        }

        return ['action' => 'start', 'watched_at' => '2026-06-28T00:00:00Z'];
    }

    public function scrobbleStop(\Phlix\Media\Library\MediaItem $item, int $progress, string $accessToken): array
    {
        $this->scrobbleStopCalls++;
        $this->lastScrobbleToken = $accessToken;

        if ($this->throwAuthOnFirstScrobble && $this->scrobbleStopCalls === 1) {
            throw new TraktAuthenticationException('Unauthorized', 401);
        }

        return ['action' => 'stop', 'watched_at' => '2026-06-28T00:00:00Z'];
    }

    public function scrobblePause(\Phlix\Media\Library\MediaItem $item, int $progress, string $accessToken): array
    {
        $this->scrobblePauseCalls++;
        $this->lastScrobbleToken = $accessToken;

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
final class FakeItemRepository
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
