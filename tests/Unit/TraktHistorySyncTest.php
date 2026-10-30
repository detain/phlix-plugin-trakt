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

    /**
     * C06 law (review trakt__srphp row 42): pulled ratings persist through the
     * canonical user_item_data.rating seam and never touch watch_history.
     *
     * The pre-fix version of this test asserted exactly(2) updateProgress
     * calls — it encoded the defect: the rating was dropped on the floor
     * (non-existent 6th argument) while the 0/0/completed upsert zeroed the
     * user's resume position, duration and progress.
     */
    public function testSyncRatingsTraktToPhlixFetchesAndPersistsRatings(): void
    {
        $ratings = [
            ['movie' => ['ids' => ['tmdb' => 123], 'title' => 'Movie 1', 'year' => 2024], 'rating' => 8, 'rated_at' => '2024-01-01T00:00:00Z'],
            ['movie' => ['ids' => ['tmdb' => 456], 'title' => 'Movie 2', 'year' => 2023], 'rating' => 9, 'rated_at' => '2024-01-02T00:00:00Z'],
        ];

        $api = $this->createMock(TraktApi::class);
        $api->method('getRatings')->willReturn($ratings);

        $db = new TraktHistorySyncTestDbDouble();
        $db->setResults([
            [['id' => 'local-id-1']],       // external-id lookup, entry 1
            [['user_id' => 'user-1']],     // profile-owner resolution, entry 1
            [],                             // rating upsert, entry 1
            [['id' => 'local-id-2']],      // external-id lookup, entry 2
            [['user_id' => 'user-2']],     // profile-owner resolution, entry 2
            [],                             // rating upsert, entry 2
        ]);

        $watchHistory = $this->getMockBuilder(WatchHistory::class)
            ->onlyMethods(['updateProgress', 'getForMediaItem'])
            ->getMock();

        $watchHistory->expects($this->never())->method('updateProgress');

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

        $result = $sync->syncRatingsTraktToPhlix('profile-1');

        $this->assertSame(2, $result);

        $calls = $db->getCalls();
        $this->assertCount(6, $calls);
        $this->assertStringContainsString('INSERT INTO user_item_data', $calls[2]['sql']);
        $this->assertSame(['user-1', 'profile-1', 'local-id-1', 8], $calls[2]['params']);
        $this->assertStringContainsString('INSERT INTO user_item_data', $calls[5]['sql']);
        $this->assertSame(['user-2', 'profile-1', 'local-id-2', 9], $calls[5]['params']);
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

    /**
     * Wire-provenance: entries below are verbatim `movie`/`episode`/`show`
     * history shapes from the Trakt API documentation for
     * `/users/{id}/history` (fetched 2026-10-30 from the official API
     * blueprint, e.g. the "Pawnee Zoo" / "The Dark Knight" samples). They are
     * the shape TraktHistorySync's parsers were written for — unlike the
     * `/watched` summary the pull historically called, whose show entries nest
     * `seasons[].episodes[]` and never carry a top-level `episode` key
     * (review trakt__srphp M5).
     */
    public function testFindMediaItemIdResolvesRealHistoryEntryShapes(): void
    {
        $movieEntry = [
            'id' => 1982346,
            'watched_at' => '2014-03-31T09:28:53.000Z',
            'action' => 'scrobble',
            'type' => 'movie',
            'movie' => [
                'title' => 'The Dark Knight',
                'year' => 2008,
                'ids' => [
                    'trakt' => 4,
                    'slug' => 'the-dark-knight-2008',
                    'imdb' => 'tt0468569',
                    'tmdb' => 155,
                ],
            ],
        ];

        $episodeEntry = [
            'id' => 1982347,
            'watched_at' => '2014-02-27T09:28:53.000Z',
            'action' => 'checkin',
            'type' => 'episode',
            'episode' => [
                'season' => 2,
                'number' => 1,
                'title' => 'Pawnee Zoo',
                'ids' => [
                    'trakt' => 251,
                    'tvdb' => 797571,
                    'imdb' => null,
                    'tmdb' => 397629,
                ],
            ],
            'show' => [
                'title' => 'Parks and Recreation',
                'year' => 2009,
                'ids' => [
                    'trakt' => 4,
                    'slug' => 'parks-and-recreation',
                    'tvdb' => 84912,
                    'imdb' => 'tt1266020',
                    'tmdb' => 8592,
                ],
            ],
        ];

        // A show-level history entry carries neither top-level movie/episode.
        $showEntry = [
            'id' => 1982349,
            'watched_at' => '2014-02-20T09:28:53.000Z',
            'action' => 'watch',
            'type' => 'show',
            'show' => [
                'title' => 'Breaking Bad',
                'year' => 2008,
                'ids' => [
                    'trakt' => 1388,
                    'slug' => 'breaking-bad',
                    'tvdb' => 81189,
                    'imdb' => 'tt0903747',
                    'tmdb' => 1396,
                ],
            ],
        ];

        $db = new TraktHistorySyncTestDbDouble();
        $db->setResults([['id' => 'media-uuid-dark-knight']]);
        $sync = $this->createSync($db);

        $this->assertSame(
            'media-uuid-dark-knight',
            $this->invokeFindMediaItemId($sync, $movieEntry),
            'movie history entries resolve through their external ids',
        );

        $db2 = new TraktHistorySyncTestDbDouble();
        $db2->setResults([['id' => 'media-uuid-pawnee-zoo']]);
        $sync2 = $this->createSync($db2);

        $this->assertSame(
            'media-uuid-pawnee-zoo',
            $this->invokeFindMediaItemId($sync2, $episodeEntry),
            'episode history entries resolve through their external ids — the shape the /watched endpoint never produced',
        );

        $this->assertNull(
            $this->invokeFindMediaItemId($sync2, $showEntry),
            'show-level entries have no local analogue and are skipped by design',
        );
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
