<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins\Scrobbler\Trakt;

use Phlix\Auth\WatchHistory;
use Phlix\Plugins\Scrobbler\Trakt\TraktApi;
use Phlix\Plugins\Scrobbler\Trakt\TraktHistorySync;
use Phlix\Plugins\Scrobbler\Trakt\TraktSettings;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Workerman\MySQL\Connection;

/**
 * Tests for TraktHistorySync findMediaItemId behavior.
 *
 * Uses reflection to test the private findMediaItemId method with a custom DB double.
 */
final class TraktHistorySyncTest extends TestCase
{
    private function createSync(Connection $db): TraktHistorySync
    {
        $api = $this->createMock(TraktApi::class);
        $watchHistory = $this->createMock(WatchHistory::class);
        $settings = new TraktSettings(
            accessToken: 'test-access',
            refreshToken: 'test-refresh',
            username: 'testuser'
        );

        return new TraktHistorySync(
            $api,
            $watchHistory,
            $settings,
            $db,
            new NullLogger()
        );
    }

    /**
     * Use reflection to invoke the private findMediaItemId method.
     */
    private function invokeFindMediaItemId(TraktHistorySync $sync, array $item): ?string
    {
        $reflection = new \ReflectionClass($sync);
        $method = $reflection->getMethod('findMediaItemId');
        $method->setAccessible(true);

        return $method->invoke($sync, $item);
    }

    public function testFindMediaItemIdReturnsIdWhenTmdbIdMatches(): void
    {
        $db = new TraktHistorySyncTestDbDouble();
        $db->setResults([['id' => 'media-item-uuid-123']]);
        $sync = $this->createSync($db);

        $item = [
            'movie' => [
                'ids' => [
                    'trakt' => 1,
                    'slug' => 'movie-slug',
                    'imdb' => 'tt1234567',
                    'tmdb' => 123,
                ],
            ],
        ];

        $result = $this->invokeFindMediaItemId($sync, $item);

        $this->assertSame('media-item-uuid-123', $result);

        $calls = $db->getCalls();
        $this->assertCount(1, $calls);
        $this->assertStringContainsString('SELECT id FROM media_items WHERE metadata_json LIKE', $calls[0]['sql']);
        $this->assertSame(['%"tmdb_id":"123"%'], $calls[0]['params']);
    }

    public function testFindMediaItemIdReturnsNullWhenNoIdsMatch(): void
    {
        $db = new TraktHistorySyncTestDbDouble();
        $db->setResults([[], [], []]);
        $sync = $this->createSync($db);

        $item = [
            'movie' => [
                'ids' => [
                    'trakt' => 1,
                    'slug' => 'movie-slug',
                    'imdb' => 'tt1234567',
                    'tmdb' => 123,
                ],
            ],
        ];

        $result = $this->invokeFindMediaItemId($sync, $item);

        $this->assertNull($result);
    }

    public function testFindMediaItemIdReturnsNullWhenItemHasNoMovieOrEpisode(): void
    {
        $db = new TraktHistorySyncTestDbDouble();
        $db->setResults([]);
        $sync = $this->createSync($db);

        $item = [
            'watched_at' => '2024-01-01T00:00:00Z',
            'action' => 'watch',
        ];

        $result = $this->invokeFindMediaItemId($sync, $item);

        $this->assertNull($result);
        $this->assertCount(0, $db->getCalls());
    }

    public function testFindMediaItemIdTriesTvdbWhenTmdbNotFound(): void
    {
        $db = new TraktHistorySyncTestDbDouble();
        $db->setResults([[], ['id' => 'tvdb-media-item']]);
        $sync = $this->createSync($db);

        $item = [
            'episode' => [
                'ids' => [
                    'trakt' => 2,
                    'tvdb' => 456,
                    'imdb' => 'tt7654321',
                    'tmdb' => 789,
                ],
            ],
        ];

        $result = $this->invokeFindMediaItemId($sync, $item);

        $this->assertSame('tvdb-media-item', $result);
    }

    public function testFindMediaItemIdTriesImdbWhenTmdbAndTvdbNotFound(): void
    {
        $db = new TraktHistorySyncTestDbDouble();
        $db->setResults([[], [], ['id' => 'imdb-media-item']]);
        $sync = $this->createSync($db);

        $item = [
            'movie' => [
                'ids' => [
                    'trakt' => 3,
                    'slug' => 'movie-slug-2',
                    'imdb' => 'tt9999999',
                    'tmdb' => 111,
                ],
            ],
        ];

        $result = $this->invokeFindMediaItemId($sync, $item);

        $this->assertSame('imdb-media-item', $result);
    }

    public function testFindMediaItemIdUsesPreResolvedIdWhenPresent(): void
    {
        $db = new TraktHistorySyncTestDbDouble();
        $db->setResults([]);
        $sync = $this->createSync($db);

        $item = [
            'movie' => [
                'ids' => [
                    'trakt' => 1,
                    'tmdb' => 123,
                ],
            ],
            '_resolved_media_item_id' => 'pre-resolved-uuid',
        ];

        $result = $this->invokeFindMediaItemId($sync, $item);

        $this->assertSame('pre-resolved-uuid', $result);
        $this->assertCount(0, $db->getCalls());
    }

    public function testFindMediaItemIdHandlesEpisodeIds(): void
    {
        $db = new TraktHistorySyncTestDbDouble();
        $db->setResults([['id' => 'episode-media-item']]);
        $sync = $this->createSync($db);

        $item = [
            'episode' => [
                'ids' => [
                    'trakt' => 2,
                    'tvdb' => 345,
                    'imdb' => 'tt6543210',
                    'tmdb' => 456,
                ],
            ],
        ];

        $result = $this->invokeFindMediaItemId($sync, $item);

        $this->assertSame('episode-media-item', $result);
    }

    public function testFindMediaItemIdSkipsEmptyExternalId(): void
    {
        $db = new TraktHistorySyncTestDbDouble();
        $db->setResults([[], ['id' => 'skip-empty']]);
        $sync = $this->createSync($db);

        $item = [
            'movie' => [
                'ids' => [
                    'trakt' => 1,
                    'slug' => 'movie-slug',
                    'imdb' => '',  // empty - should be skipped
                    'tmdb' => 123,
                ],
            ],
        ];

        $result = $this->invokeFindMediaItemId($sync, $item);

        $this->assertSame('skip-empty', $result);
    }
}

/**
 * Custom DB double for TraktHistorySync tests.
 * Tracks query calls and returns configurable results.
 */
class TraktHistorySyncTestDbDouble extends Connection
{
    /** @var array<array> */
    private array $resultsToReturn = [];
    /** @var array<array{sql: string, params: array}> */
    private array $calls = [];

    public function __construct()
    {
        // Parent constructor requires params but they won't be used in tests
        parent::__construct('localhost', '3306', 'user', 'pass', 'test');
    }

    public function setResults(array $results): void
    {
        $this->resultsToReturn = $results;
        $this->calls = [];
    }

    /**
     * @return array<array{sql: string, params: array}>
     */
    public function getCalls(): array
    {
        return $this->calls;
    }

        public function query(string $sql, array $params = []): array
        {
            $this->calls[] = ['sql' => $sql, 'params' => $params];

            if (!empty($this->resultsToReturn)) {
                $result = array_shift($this->resultsToReturn);
                // Real Workerman returns [[row]] format for a single row
                // so $result[0]['id'] works. Fix the format here.
                if (isset($result[0]) && is_array($result[0])) {
                    return $result;
                }
                // Single row returned - wrap in outer array
                return [$result];
            }

            return [];
        }
}
