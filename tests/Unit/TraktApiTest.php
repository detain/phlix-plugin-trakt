<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins\Scrobbler\Trakt;

use Phlix\Plugins\Scrobbler\Trakt\HttpClient;
use Phlix\Plugins\Scrobbler\Trakt\HttpClientInterface;
use Phlix\Plugins\Scrobbler\Trakt\TraktApi;
use Phlix\Plugins\Scrobbler\Trakt\TraktApiException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class TraktApiTest extends TestCase
{
    private const CLIENT_ID = 'test-client-id';
    private const CLIENT_SECRET = 'test-client-secret';

    public function testGetAuthUrlContainsExpectedParams(): void
    {
        $http = new MockHttpClient();
        $api = new TraktApi($http, self::CLIENT_ID, self::CLIENT_SECRET, new NullLogger());

        $state = 'test-state-12345';
        $codeVerifier = 'test-code-verifier';

        $authUrl = $api->getAuthUrl($state, $codeVerifier);

        $this->assertStringContainsString('client_id=test-client-id', $authUrl);
        $this->assertStringContainsString('response_type=code', $authUrl);
        $this->assertStringContainsString('state=test-state-12345', $authUrl);
        $this->assertStringContainsString('code_challenge_method=S256', $authUrl);
        $this->assertStringContainsString('https://api.trakt.tv/oauth/authorize?', $authUrl);
    }

    public function testExchangeCodeReturnsTokens(): void
    {
        $http = new MockHttpClient([
            ['access_token' => 'new-access', 'refresh_token' => 'new-refresh', 'expires_in' => 3600]
        ]);
        $api = new TraktApi($http, self::CLIENT_ID, self::CLIENT_SECRET, new NullLogger());

        $result = $api->exchangeCode('auth-code', 'code-verifier');

        $this->assertSame('new-access', $result['access_token']);
        $this->assertSame('new-refresh', $result['refresh_token']);
        $this->assertSame(3600, $result['expires_in']);
    }

    public function testRefreshAccessTokenReturnsNewTokens(): void
    {
        $http = new MockHttpClient([
            ['access_token' => 'refreshed-access', 'refresh_token' => 'refreshed-refresh', 'expires_in' => 7200]
        ]);
        $api = new TraktApi($http, self::CLIENT_ID, self::CLIENT_SECRET, new NullLogger());

        $result = $api->refreshAccessToken('old-refresh-token');

        $this->assertSame('refreshed-access', $result['access_token']);
        $this->assertSame('refreshed-refresh', $result['refresh_token']);
        $this->assertSame(7200, $result['expires_in']);
    }

    public function testGetWatchedHistoryReturnsArray(): void
    {
        $history = [['id' => 1, 'title' => 'Test Movie']];
        $http = new MockHttpClient([$history]);
        $api = new TraktApi($http, self::CLIENT_ID, self::CLIENT_SECRET, new NullLogger());

        $result = $api->getWatchedHistory('testuser', 1, 100, 'access-token');

        $this->assertSame($history, $result);
    }

    public function testExchangeCodeThrowsOnError(): void
    {
        $http = new MockHttpClient([['error' => 'invalid_grant', 'error_description' => 'Code expired']]);
        $api = new TraktApi($http, self::CLIENT_ID, self::CLIENT_SECRET, new NullLogger());

        $this->expectException(TraktApiException::class);
        $api->exchangeCode('auth-code', 'code-verifier');
    }

    public function testRefreshTokenThrowsOnError(): void
    {
        $http = new MockHttpClient([['error' => 'invalid_grant']]);
        $api = new TraktApi($http, self::CLIENT_ID, self::CLIENT_SECRET, new NullLogger());

        $this->expectException(TraktApiException::class);
        $api->refreshAccessToken('old-refresh-token');
    }

    public function testGetWatchedHistoryUsesCorrectEndpoint(): void
    {
        $http = new MockHttpClient([[]]);
        $api = new TraktApi($http, self::CLIENT_ID, self::CLIENT_SECRET, new NullLogger());

        $api->getWatchedHistory('testuser', 1, 100, 'access-token');

        $this->assertSame('GET', $http->lastMethod);
        $this->assertStringContainsString('/users/testuser/watched', $http->lastUrl);
    }

    public function testScrobbleSkippedWhenNoExternalId(): void
    {
        $http = new MockHttpClient([['action' => 'start', 'watched_at' => '2026-06-28T00:00:00Z']]);
        $api = new TraktApi($http, self::CLIENT_ID, self::CLIENT_SECRET, new NullLogger());

        $item = new \Phlix\Media\Library\MediaItem(
            id: 'mi-no-id',
            name: 'No-ID Movie',
            type: 'movie',
            path: '/movies/no-id.mkv',
            metadata: [],
        );

        $result = $api->scrobbleStart($item, 0, 'scrobble-token');

        $this->assertSame('start', $result['action']);
        $this->assertArrayHasKey('watched_at', $result);
        $this->assertTrue($result['skipped']);
        // HTTP client must never have been called.
        $this->assertSame('', $http->lastMethod);
    }

    public function testScrobbleSkippedWhenNoExternalIdForEpisode(): void
    {
        $http = new MockHttpClient([['action' => 'pause', 'watched_at' => '2026-06-28T00:00:00Z']]);
        $api = new TraktApi($http, self::CLIENT_ID, self::CLIENT_SECRET, new NullLogger());

        $item = new \Phlix\Media\Library\MediaItem(
            id: 'mi-ep-no-id',
            name: 'No-ID Episode',
            type: 'episode',
            path: '/tv/show/s01e01.mkv',
            metadata: [],
        );

        $result = $api->scrobblePause($item, 50, 'scrobble-token');

        $this->assertSame('pause', $result['action']);
        $this->assertArrayHasKey('watched_at', $result);
        $this->assertTrue($result['skipped']);
        $this->assertSame('', $http->lastMethod);
    }

    public function testScrobbleSendsMandatoryTraktHeaders(): void
    {
        $http = new MockHttpClient([['action' => 'start', 'watched_at' => '2026-06-28T00:00:00Z']]);
        $api = new TraktApi($http, self::CLIENT_ID, self::CLIENT_SECRET, new NullLogger());

        $item = $this->makeMovieItem();
        $api->scrobbleStart($item, 0, 'scrobble-token');

        $this->assertSame('2', $http->lastHeaders['trakt-api-version'] ?? null);
        $this->assertSame(self::CLIENT_ID, $http->lastHeaders['trakt-api-key'] ?? null);
        $this->assertSame('Bearer scrobble-token', $http->lastHeaders['Authorization'] ?? null);
    }

    public function testGetWatchedHistorySendsMandatoryTraktHeaders(): void
    {
        $http = new MockHttpClient([[]]);
        $api = new TraktApi($http, self::CLIENT_ID, self::CLIENT_SECRET, new NullLogger());

        $api->getWatchedHistory('testuser', 1, 100, 'history-token');

        $this->assertSame('2', $http->lastHeaders['trakt-api-version'] ?? null);
        $this->assertSame(self::CLIENT_ID, $http->lastHeaders['trakt-api-key'] ?? null);
        $this->assertSame('Bearer history-token', $http->lastHeaders['Authorization'] ?? null);
    }

    public function testRefreshAccessTokenSendsMandatoryTraktHeaders(): void
    {
        $http = new MockHttpClient([
            ['access_token' => 'a', 'refresh_token' => 'b', 'expires_in' => 3600]
        ]);
        $api = new TraktApi($http, self::CLIENT_ID, self::CLIENT_SECRET, new NullLogger());

        $api->refreshAccessToken('old-refresh-token');

        $this->assertSame('2', $http->lastHeaders['trakt-api-version'] ?? null);
        $this->assertSame(self::CLIENT_ID, $http->lastHeaders['trakt-api-key'] ?? null);
        // No access token is available during a refresh — Authorization must be absent.
        $this->assertArrayNotHasKey('Authorization', $http->lastHeaders);
    }

    public function testExchangeCodeSendsMandatoryTraktHeaders(): void
    {
        $http = new MockHttpClient([
            ['access_token' => 'a', 'refresh_token' => 'b', 'expires_in' => 3600]
        ]);
        $api = new TraktApi($http, self::CLIENT_ID, self::CLIENT_SECRET, new NullLogger());

        $api->exchangeCode('auth-code', 'code-verifier');

        $this->assertSame('2', $http->lastHeaders['trakt-api-version'] ?? null);
        $this->assertSame(self::CLIENT_ID, $http->lastHeaders['trakt-api-key'] ?? null);
        // The token-exchange call has no token yet — Authorization must be absent.
        $this->assertArrayNotHasKey('Authorization', $http->lastHeaders);
    }

    public function testAddToHistorySendsMandatoryTraktHeaders(): void
    {
        $http = new MockHttpClient([['added' => ['movies' => 1]]]);
        $api = new TraktApi($http, self::CLIENT_ID, self::CLIENT_SECRET, new NullLogger());

        $item = $this->makeMovieItem();
        $api->addToHistory($item, new \DateTimeImmutable('2026-06-28T00:00:00Z'), 'history-token');

        $this->assertSame('2', $http->lastHeaders['trakt-api-version'] ?? null);
        $this->assertSame(self::CLIENT_ID, $http->lastHeaders['trakt-api-key'] ?? null);
        $this->assertSame('Bearer history-token', $http->lastHeaders['Authorization'] ?? null);
    }

    public function testScrobbleStartPostsToStartEndpointWithMoviePayload(): void
    {
        $http = new MockHttpClient([['action' => 'start', 'watched_at' => '2026-06-28T00:00:00Z']]);
        $api = new TraktApi($http, self::CLIENT_ID, self::CLIENT_SECRET, new NullLogger());

        $api->scrobbleStart($this->makeMovieItem(), 42, 'scrobble-token');

        // Action is in the URL path, NOT the body (Trakt scrobble contract).
        $this->assertSame('POST', $http->lastMethod);
        $this->assertSame('https://api.trakt.tv/scrobble/start', $http->lastUrl);
        // Body carries progress + the movie object, and MUST NOT carry `action`
        // or a null `episode` sibling key.
        $this->assertSame(42, $http->lastData['progress'] ?? null);
        $this->assertArrayHasKey('movie', $http->lastData);
        $this->assertArrayNotHasKey('episode', $http->lastData);
        $this->assertArrayNotHasKey('action', $http->lastData);
        $this->assertSame(1, $http->lastData['movie']['ids']['trakt'] ?? null);
    }

    public function testScrobbleStopPostsToStopEndpoint(): void
    {
        $http = new MockHttpClient([['action' => 'stop', 'watched_at' => '2026-06-28T00:00:00Z']]);
        $api = new TraktApi($http, self::CLIENT_ID, self::CLIENT_SECRET, new NullLogger());

        $api->scrobbleStop($this->makeMovieItem(), 99, 'scrobble-token');

        $this->assertSame('https://api.trakt.tv/scrobble/stop', $http->lastUrl);
        $this->assertSame(99, $http->lastData['progress'] ?? null);
    }

    public function testScrobblePausePostsEpisodePayloadToPauseEndpoint(): void
    {
        $http = new MockHttpClient([['action' => 'pause', 'watched_at' => '2026-06-28T00:00:00Z']]);
        $api = new TraktApi($http, self::CLIENT_ID, self::CLIENT_SECRET, new NullLogger());

        $item = new \Phlix\Media\Library\MediaItem(
            id: 'ep-1',
            name: 'Test Episode',
            type: 'episode',
            path: '/tv/show/s01e02.mkv',
            metadata: ['tvdb_id' => 555, 'season_number' => 1, 'episode_number' => 2],
        );

        $api->scrobblePause($item, 15, 'scrobble-token');

        $this->assertSame('https://api.trakt.tv/scrobble/pause', $http->lastUrl);
        // Episode item → episode key present, movie key absent.
        $this->assertArrayHasKey('episode', $http->lastData);
        $this->assertArrayNotHasKey('movie', $http->lastData);
        $this->assertArrayNotHasKey('action', $http->lastData);
        $this->assertSame(555, $http->lastData['episode']['ids']['tvdb'] ?? null);
        $this->assertSame(2, $http->lastData['episode']['number'] ?? null);
    }

    /**
     * Build a minimal movie MediaItem fixture for scrobble/history calls.
     *
     * Phlix\Media\Library\MediaItem is supplied by the host server and is not
     * part of this plugin's dependency closure; a matching stub is registered
     * below when the real class is unavailable.
     */
    private function makeMovieItem(): \Phlix\Media\Library\MediaItem
    {
        return new \Phlix\Media\Library\MediaItem(
            id: 'mi-1',
            name: 'Test Movie',
            type: 'movie',
            path: '/movies/test.mkv',
            metadata: ['trakt_id' => 1, 'imdb_id' => 'tt0000001', 'tmdb_id' => 42],
        );
    }
}

/**
 * Minimal stand-in for the host-supplied Phlix\Media\Library\MediaItem.
 *
 * The real class lives in the Phlix server and is not part of this plugin's
 * dependency closure, so the header tests register this stub under the
 * canonical FQCN when the real class is unavailable. Only the public
 * properties TraktApi reads (type/metadata/name) plus the named-argument
 * constructor signature matter here.
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
     * Mirrors the host MediaItem::fromRow() factory so plugin code that
     * resolves an item via the repository (e.g. TraktPlugin::findMediaItem())
     * works against this shared canonical stub. Unused by the API tests.
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

if (!class_exists(\Phlix\Media\Library\MediaItem::class)) {
    \class_alias(MediaItemStub::class, \Phlix\Media\Library\MediaItem::class);
}

final class MockHttpClient implements HttpClientInterface
{
    public string $lastMethod = '';
    public string $lastUrl = '';
    public array $lastData = [];
    public array $lastHeaders = [];

    /** @var array<array> */
    private array $responses;
    private int $responseIndex = 0;

    /**
     * @param array<array> $responses Queue of responses to return
     */
    public function __construct(array $responses = [])
    {
        $this->responses = $responses;
    }

    public function get(string $url, array $params = [], array $headers = []): array
    {
        $this->lastMethod = 'GET';
        $this->lastUrl = $url;
        $this->lastHeaders = $headers;

        if (!empty($params)) {
            $this->lastUrl .= '?' . http_build_query($params);
        }

        return $this->getNextResponse();
    }

    public function post(string $url, array $data = [], array $headers = []): array
    {
        $this->lastMethod = 'POST';
        $this->lastUrl = $url;
        $this->lastData = $data;
        $this->lastHeaders = $headers;

        return $this->getNextResponse();
    }

    private function getNextResponse(): array
    {
        if ($this->responseIndex >= count($this->responses)) {
            return [];
        }

        return $this->responses[$this->responseIndex++];
    }
}
