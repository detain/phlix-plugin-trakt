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
        // Now uses JSON_EXTRACT for robust JSON querying, fallback to LIKE if needed
        $this->assertStringContainsString('JSON_EXTRACT', $calls[0]['sql']);
        $this->assertSame(['$.tmdb_id', '123'], $calls[0]['params']);
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
        $db->setResults([[], ['id' => 'imdb-media-item']]);
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
                    'tvdb' => 456,  // tvdb is tried when tmdb fails and imdb is empty
                ],
            ],
        ];

        $result = $this->invokeFindMediaItemId($sync, $item);

        $this->assertSame('skip-empty', $result);
    }

    // --- Rating sync tests ---------------------------------------------------

    public function testSyncRatingsTraktToPhlixReturnsZeroWhenNotConfigured(): void
    {
        $api = $this->createMock(TraktApi::class);
        $db = new TraktHistorySyncTestDbDouble();
        $watchHistory = $this->createMock(WatchHistory::class);
        $settings = new TraktSettings(
            accessToken: null,
            refreshToken: null,
            username: '', // not configured
        );

        $sync = new TraktHistorySync(
            $api,
            $watchHistory,
            $settings,
            $db,
            new NullLogger()
        );

        $this->assertSame(0, $sync->syncRatingsTraktToPhlix('default'));
    }

    public function testSyncRatingsTraktToPhlixReturnsZeroWhenSyncDisabled(): void
    {
        $api = $this->createMock(TraktApi::class);
        $db = new TraktHistorySyncTestDbDouble();
        $watchHistory = $this->createMock(WatchHistory::class);
        $settings = new TraktSettings(
            accessToken: 'a',
            refreshToken: 'r',
            username: 'testuser',
            syncEnabled: false,
        );

        $sync = new TraktHistorySync(
            $api,
            $watchHistory,
            $settings,
            $db,
            new NullLogger()
        );

        $this->assertSame(0, $sync->syncRatingsTraktToPhlix('default'));
    }

    public function testSyncRatingsTraktToPhlixFetchesAndUpdatesRatings(): void
    {
        $ratings = [
            ['movie' => ['ids' => ['tmdb' => 123], 'title' => 'Movie 1', 'year' => 2024], 'rating' => 8, 'rated_at' => '2024-01-01T00:00:00Z'],
            ['movie' => ['ids' => ['tmdb' => 456], 'title' => 'Movie 2', 'year' => 2023], 'rating' => 9, 'rated_at' => '2024-01-02T00:00:00Z'],
        ];

        $api = $this->createMock(TraktApi::class);
        $api->method('getRatings')->willReturn($ratings);

        $db = new TraktHistorySyncTestDbDouble();
        $db->setResults([[['id' => 'local-id-1']], [['id' => 'local-id-2']]]);

        $watchHistory = $this->getMockBuilder(WatchHistory::class)
            ->onlyMethods(['updateProgress', 'getForMediaItem'])
            ->getMock();

        $watchHistory->expects($this->exactly(2))->method('updateProgress');

        $settings = new TraktSettings(
            accessToken: 'a',
            refreshToken: 'r',
            username: 'testuser',
            syncEnabled: true,
        );

        $sync = new TraktHistorySync(
            $api,
            $watchHistory,
            $settings,
            $db,
            new NullLogger()
        );

        $result = $sync->syncRatingsTraktToPhlix('default');

        $this->assertSame(2, $result);
    }

    public function testSyncRatingsPhlixToTraktReturnsFalseWhenNotConfigured(): void
    {
        $api = $this->createMock(TraktApi::class);
        $db = new TraktHistorySyncTestDbDouble();
        $watchHistory = $this->createMock(WatchHistory::class);
        $settings = new TraktSettings(
            accessToken: null,
            refreshToken: null,
            username: '', // not configured
        );

        $sync = new TraktHistorySync(
            $api,
            $watchHistory,
            $settings,
            $db,
            new NullLogger()
        );

        $this->assertFalse($sync->syncRatingsPhlixToTrakt('media-id', 8));
    }

    public function testSyncRatingsPhlixToTraktReturnsFalseWhenSyncDisabled(): void
    {
        $api = $this->createMock(TraktApi::class);
        $db = new TraktHistorySyncTestDbDouble();
        $watchHistory = $this->createMock(WatchHistory::class);
        $settings = new TraktSettings(
            accessToken: 'a',
            refreshToken: 'r',
            username: 'testuser',
            syncEnabled: false,
        );

        $sync = new TraktHistorySync(
            $api,
            $watchHistory,
            $settings,
            $db,
            new NullLogger()
        );

        $this->assertFalse($sync->syncRatingsPhlixToTrakt('media-id', 8));
    }

    public function testSyncRatingsPhlixToTraktReturnsFalseWhenNoLocalHistory(): void
    {
        $api = $this->createMock(TraktApi::class);
        $db = new TraktHistorySyncTestDbDouble();
        $watchHistory = $this->createMock(WatchHistory::class);
        $watchHistory->method('getForMediaItem')->willReturn(null);

        $settings = new TraktSettings(
            accessToken: 'a',
            refreshToken: 'r',
            username: 'testuser',
            syncEnabled: true,
        );

        $sync = new TraktHistorySync(
            $api,
            $watchHistory,
            $settings,
            $db,
            new NullLogger()
        );

        $this->assertFalse($sync->syncRatingsPhlixToTrakt('media-id', 8));
    }

    public function testSyncRatingsPhlixToTraktCallsAddRating(): void
    {
        $api = $this->createMock(TraktApi::class);
        $api->expects($this->once())->method('addRating')->willReturn(['added' => true]);

        $db = new TraktHistorySyncTestDbDouble();
        $watchHistory = $this->createMock(WatchHistory::class);
        $watchHistory->method('getForMediaItem')->willReturn([
            'media_name' => 'Test Movie',
            'media_type' => 'movie',
            'metadata' => ['trakt_id' => 1],
        ]);

        $settings = new TraktSettings(
            accessToken: 'a',
            refreshToken: 'r',
            username: 'testuser',
            syncEnabled: true,
        );

        $sync = new TraktHistorySync(
            $api,
            $watchHistory,
            $settings,
            $db,
            new NullLogger()
        );

        $result = $sync->syncRatingsPhlixToTrakt('media-id', 7);

        $this->assertTrue($result);
    }

    public function testSyncRatingsPhlixToTraktClampsRatingToOneThroughTen(): void
    {
        $api = $this->createMock(TraktApi::class);
        $api->expects($this->once())
            ->method('addRating')
            ->with(
                $this->anything(),
                10, // 15 should be clamped to 10
                $this->anything()
            )
            ->willReturn(['added' => true]);

        $db = new TraktHistorySyncTestDbDouble();
        $watchHistory = $this->createMock(WatchHistory::class);
        $watchHistory->method('getForMediaItem')->willReturn([
            'media_name' => 'Test Movie',
            'media_type' => 'movie',
            'metadata' => [],
        ]);

        $settings = new TraktSettings(
            accessToken: 'a',
            refreshToken: 'r',
            username: 'testuser',
            syncEnabled: true,
        );

        $sync = new TraktHistorySync(
            $api,
            $watchHistory,
            $settings,
            $db,
            new NullLogger()
        );

        $result = $sync->syncRatingsPhlixToTrakt('media-id', 15);

        $this->assertTrue($result);
    }

    // --- Private method tests via reflection --------------------------------

    public function testFindMediaItemIdByTitleYearReturnsNullWhenTitleEmpty(): void
    {
        $db = new TraktHistorySyncTestDbDouble();
        $sync = $this->createSync($db);

        $ref = new \ReflectionClass($sync);
        $method = $ref->getMethod('findMediaItemIdByTitleYear');
        $method->setAccessible(true);

        $result = $method->invoke($sync, '', 2024, 'movie');

        $this->assertNull($result);
    }

    public function testFindMediaItemIdByTitleYearQueriesDbWithTitleAndYear(): void
    {
        $db = new TraktHistorySyncTestDbDouble();
        $db->setResults([[['id' => 'found-by-title']]]);
        $sync = $this->createSync($db);

        $ref = new \ReflectionClass($sync);
        $method = $ref->getMethod('findMediaItemIdByTitleYear');
        $method->setAccessible(true);

        $result = $method->invoke($sync, 'Test Movie', 2024, 'movie');

        $this->assertSame('found-by-title', $result);

        $calls = $db->getCalls();
        $this->assertCount(1, $calls);
        $this->assertStringContainsString('name LIKE', $calls[0]['sql']);
    }

    public function testParseWatchedAtWithValidDate(): void
    {
        $db = new TraktHistorySyncTestDbDouble();
        $sync = $this->createSync($db);

        $ref = new \ReflectionClass($sync);
        $method = $ref->getMethod('parseWatchedAt');
        $method->setAccessible(true);

        $result = $method->invoke($sync, ['watched_at' => '2024-06-15T10:30:00Z']);

        $this->assertSame('2024', $result->format('Y'));
        $this->assertSame('06', $result->format('m'));
        $this->assertSame('15', $result->format('d'));
    }

    public function testParseWatchedAtWithInvalidDateFallsBackToNow(): void
    {
        $db = new TraktHistorySyncTestDbDouble();
        $sync = $this->createSync($db);

        $ref = new \ReflectionClass($sync);
        $method = $ref->getMethod('parseWatchedAt');
        $method->setAccessible(true);

        $before = new \DateTimeImmutable();
        $result = $method->invoke($sync, ['watched_at' => 'not-a-date']);
        $after = new \DateTimeImmutable();

        // Should fall back to "now" which is within the same second
        $this->assertGreaterThanOrEqual($before->format('Y-m-d'), $result->format('Y-m-d'));
        $this->assertLessThanOrEqual($after->format('Y-m-d'), $result->format('Y-m-d'));
    }

    public function testParseWatchedAtWithMissingKeyFallsBackToNow(): void
    {
        $db = new TraktHistorySyncTestDbDouble();
        $sync = $this->createSync($db);

        $ref = new \ReflectionClass($sync);
        $method = $ref->getMethod('parseWatchedAt');
        $method->setAccessible(true);

        $result = $method->invoke($sync, []);

        $before = new \DateTimeImmutable();
        $this->assertGreaterThanOrEqual($before->modify('-1 second'), $result);
    }

    public function testExtractDurationTicksFromTopLevel(): void
    {
        $db = new TraktHistorySyncTestDbDouble();
        $sync = $this->createSync($db);

        $ref = new \ReflectionClass($sync);
        $method = $ref->getMethod('extractDurationTicks');
        $method->setAccessible(true);

        $result = $method->invoke($sync, ['runtime' => 120]);

        // 120 seconds * 10_000_000 ticks per second = 1_200_000_000
        $this->assertSame(1_200_000_000, $result);
    }

    public function testExtractDurationTicksFromMovieObject(): void
    {
        $db = new TraktHistorySyncTestDbDouble();
        $sync = $this->createSync($db);

        $ref = new \ReflectionClass($sync);
        $method = $ref->getMethod('extractDurationTicks');
        $method->setAccessible(true);

        $result = $method->invoke($sync, [
            'movie' => ['runtime' => 90],
        ]);

        // 90 seconds * 10_000_000 = 900_000_000
        $this->assertSame(900_000_000, $result);
    }

    public function testExtractDurationTicksFromEpisodeObject(): void
    {
        $db = new TraktHistorySyncTestDbDouble();
        $sync = $this->createSync($db);

        $ref = new \ReflectionClass($sync);
        $method = $ref->getMethod('extractDurationTicks');
        $method->setAccessible(true);

        $result = $method->invoke($sync, [
            'episode' => ['runtime' => 45],
        ]);

        // 45 seconds * 10_000_000 = 450_000_000
        $this->assertSame(450_000_000, $result);
    }

    public function testExtractDurationTicksReturnsZeroWhenUnknown(): void
    {
        $db = new TraktHistorySyncTestDbDouble();
        $sync = $this->createSync($db);

        $ref = new \ReflectionClass($sync);
        $method = $ref->getMethod('extractDurationTicks');
        $method->setAccessible(true);

        $result = $method->invoke($sync, ['action' => 'watch']);

        $this->assertSame(0, $result);
    }

    public function testBuildMediaItemCreatesCorrectInstance(): void
    {
        $db = new TraktHistorySyncTestDbDouble();
        $sync = $this->createSync($db);

        $ref = new \ReflectionClass($sync);
        $method = $ref->getMethod('buildMediaItem');
        $method->setAccessible(true);

        $result = $method->invoke($sync, 'test-id', [
            'media_name' => 'Test Movie',
            'media_type' => 'movie',
            'metadata' => ['trakt_id' => 123],
        ]);

        $this->assertInstanceOf(\Phlix\Media\Library\MediaItem::class, $result);
        $this->assertSame('test-id', $result->id);
        $this->assertSame('Test Movie', $result->name);
        $this->assertSame('movie', $result->type);
        $this->assertSame(['trakt_id' => 123], $result->metadata);
    }

    public function testBuildMediaItemHandlesMissingFields(): void
    {
        $db = new TraktHistorySyncTestDbDouble();
        $sync = $this->createSync($db);

        $ref = new \ReflectionClass($sync);
        $method = $ref->getMethod('buildMediaItem');
        $method->setAccessible(true);

        $result = $method->invoke($sync, 'test-id', []);

        $this->assertSame('test-id', $result->id);
        $this->assertSame('Unknown', $result->name);
        $this->assertSame('movie', $result->type);
        $this->assertSame([], $result->metadata);
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
