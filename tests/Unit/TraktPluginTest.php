<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins\Scrobbler\Trakt;

use Phlix\Plugins\Scrobbler\Trakt\HttpClientInterface;
use Phlix\Plugins\Scrobbler\Trakt\TraktApi;
use Phlix\Plugins\Scrobbler\Trakt\TraktAuthenticationException;
use Phlix\Plugins\Scrobbler\Trakt\TraktPlugin;
use Phlix\Plugins\Scrobbler\Trakt\TraktSettings;
use Phlix\Plugins\Scrobbler\Trakt\TraktSettingsRepository;
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
        $this->assertSame('onPlaybackStarted', $events[PlaybackStarted::class]);
        $this->assertSame('onPlaybackStopped', $events[PlaybackStopped::class]);
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

    // --- helpers ------------------------------------------------------------

    private function makeWiredPlugin(
        FakeTraktApi $api,
        ?RecordingSettingsRepository $repo,
        TraktSettings $settings,
    ): TraktPlugin {
        $plugin = new TraktPlugin($settings, new NullLogger(), $api, $repo);

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
}

/**
 * Fake TraktApi that records refresh/scrobble calls and can simulate a 401.
 */
final class FakeTraktApi extends TraktApi
{
    public int $refreshCalls = 0;
    public int $scrobbleStartCalls = 0;
    public int $scrobbleStopCalls = 0;
    public bool $throwAuthOnFirstScrobble = false;
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
