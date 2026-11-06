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
use Workerman\MySQL\TestableConnection;

/**
 * Tests for TraktHistorySync public sync methods: syncTraktToPhlix and syncPhlixToTrakt.
 */
final class TraktHistorySyncPublicMethodsTest extends TestCase
{
    private function createSync(
        ?TraktApi $api = null,
        ?WatchHistory $watchHistory = null,
        ?TraktSettings $settings = null,
        ?TestableConnection $db = null,
    ): TraktHistorySync {
        return new TraktHistorySync(
            $api ?? $this->createMock(TraktApi::class),
            $watchHistory ?? $this->createMock(WatchHistory::class),
            $settings ?? new TraktSettings(
                accessToken: 'test-access',
                refreshToken: 'test-refresh',
                username: 'testuser',
                syncEnabled: true,
            ),
            $db ?? new TestableConnection(),
            new NullLogger(),
        );
    }

    // --- syncTraktToPhlix tests -----------------------------------------------

    public function testSyncTraktToPhlixReturnsZeroWhenNotConfigured(): void
    {
        $settings = new TraktSettings(
            accessToken: null,
            refreshToken: null,
            username: '', // not configured
            syncEnabled: true,
        );

        $sync = $this->createSync(settings: $settings);

        $result = $sync->syncTraktToPhlix('default');

        $this->assertSame(0, $result);
    }

    public function testSyncTraktToPhlixReturnsZeroWhenSyncDisabled(): void
    {
        $settings = new TraktSettings(
            accessToken: 'test-access',
            refreshToken: 'test-refresh',
            username: 'testuser',
            syncEnabled: false,
        );

        $sync = $this->createSync(settings: $settings);

        $result = $sync->syncTraktToPhlix('default');

        $this->assertSame(0, $result);
    }

    public function testSyncTraktToPhlixReturnsZeroWhenApiThrowsException(): void
    {
        $api = $this->createMock(TraktApi::class);
        $api->method('getWatchedHistory')
            ->willThrowException(new \Phlix\Plugins\Scrobbler\Trakt\TraktApiException('API error'));

        $sync = $this->createSync($api);

        $result = $sync->syncTraktToPhlix('default');

        $this->assertSame(0, $result);
    }

    public function testSyncTraktToPhlixWritesEntriesForNewItems(): void
    {
        // Trakt history items (returned directly, not wrapped in array)
        $traktHistory = [
            [
                'movie' => [
                    'ids' => ['tmdb' => 123],
                ],
                'watched_at' => '2024-01-15T10:30:00Z',
                'runtime' => 120,
            ],
            [
                'movie' => [
                    'ids' => ['tmdb' => 456],
                ],
                'watched_at' => '2024-01-16T14:00:00Z',
                'runtime' => 90,
            ],
        ];

        $api = $this->createMock(TraktApi::class);
        $api->method('getWatchedHistory')
            ->willReturn($traktHistory);

        $db = new TestableConnection();
        // First item found, second item found (both by tmdb_id)
        $db->setResults([
            [['id' => 'local-id-123']], // find by tmdb 123
            [['id' => 'local-id-456']], // find by tmdb 456
        ]);

        $watchHistory = $this->getMockBuilder(WatchHistory::class)
            ->onlyMethods(['getForMediaItem', 'updateProgress'])
            ->getMock();

        // Both items return null (no existing history)
        $watchHistory->method('getForMediaItem')->willReturn(null);

        // Expect updateProgress to be called twice (once per item)
        $watchHistory->expects($this->exactly(2))->method('updateProgress');

        $sync = $this->createSync($api, $watchHistory, null, $db);

        $result = $sync->syncTraktToPhlix('default');

        $this->assertSame(2, $result);
    }

    public function testSyncTraktToPhlixSkipsItemsAlreadyAt90Percent(): void
    {
        $traktHistory = [
            [
                'movie' => [
                    'ids' => ['tmdb' => 123],
                ],
                'watched_at' => '2024-01-15T10:30:00Z',
                'runtime' => 120,
            ],
        ];

        $api = $this->createMock(TraktApi::class);
        $api->method('getWatchedHistory')->willReturn($traktHistory);

        $db = new TestableConnection();
        $db->setResults([[['id' => 'local-id-123']]]);

        $watchHistory = $this->getMockBuilder(WatchHistory::class)
            ->onlyMethods(['getForMediaItem', 'updateProgress'])
            ->getMock();

        // Item already at 95% (above COMPLETED_THRESHOLD of 90)
        $watchHistory->method('getForMediaItem')
            ->willReturn(['progress_percent' => 95.0]);

        // updateProgress should NOT be called since item is already complete
        $watchHistory->expects($this->never())->method('updateProgress');

        $sync = $this->createSync($api, $watchHistory, null, $db);

        $result = $sync->syncTraktToPhlix('default');

        $this->assertSame(0, $result);
    }

    public function testSyncTraktToPhlixSkipsItemsNotFoundLocally(): void
    {
        $traktHistory = [
            [
                'movie' => [
                    'ids' => ['tmdb' => 999],
                ],
                'watched_at' => '2024-01-15T10:30:00Z',
                'runtime' => 120,
            ],
        ];

        $api = $this->createMock(TraktApi::class);
        $api->method('getWatchedHistory')->willReturn($traktHistory);

        $db = new TestableConnection();
        $db->setResults([[]]); // not found

        $watchHistory = $this->getMockBuilder(WatchHistory::class)
            ->onlyMethods(['getForMediaItem', 'updateProgress'])
            ->getMock();

        $watchHistory->expects($this->never())->method('updateProgress');

        $sync = $this->createSync($api, $watchHistory, null, $db);

        $result = $sync->syncTraktToPhlix('default');

        $this->assertSame(0, $result);
    }

    public function testSyncTraktToPhlixHandlesEpisodeItems(): void
    {
        $traktHistory = [
            [
                'episode' => [
                    'ids' => ['tvdb' => 123],
                    'runtime' => 45,
                ],
                'watched_at' => '2024-01-15T10:30:00Z',
            ],
        ];

        $api = $this->createMock(TraktApi::class);
        $api->method('getWatchedHistory')->willReturn($traktHistory);

        $db = new TestableConnection();
        $db->setResults([[['id' => 'episode-local-id']]]);

        $watchHistory = $this->getMockBuilder(WatchHistory::class)
            ->onlyMethods(['getForMediaItem', 'updateProgress'])
            ->getMock();

        $watchHistory->method('getForMediaItem')->willReturn(null);
        $watchHistory->expects($this->once())->method('updateProgress');

        $sync = $this->createSync($api, $watchHistory, null, $db);

        $result = $sync->syncTraktToPhlix('default');

        $this->assertSame(1, $result);
    }

    // --- syncPhlixToTrakt tests ----------------------------------------------

    public function testSyncPhlixToTraktReturnsFalseWhenNotConfigured(): void
    {
        $settings = new TraktSettings(
            accessToken: null,
            refreshToken: null,
            username: '',
            syncEnabled: true,
        );

        $sync = $this->createSync(settings: $settings);

        $result = $sync->syncPhlixToTrakt('media-id', 'now', 0, null);

        $this->assertFalse($result);
    }

    public function testSyncPhlixToTraktReturnsFalseWhenSyncDisabled(): void
    {
        $settings = new TraktSettings(
            accessToken: 'test-access',
            refreshToken: 'test-refresh',
            username: 'testuser',
            syncEnabled: false,
        );

        $sync = $this->createSync(settings: $settings);

        $result = $sync->syncPhlixToTrakt('media-id', 'now', 0, null);

        $this->assertFalse($result);
    }

    public function testSyncPhlixToTraktReturnsFalseWhenNoLocalHistory(): void
    {
        $watchHistory = $this->createMock(WatchHistory::class);
        $watchHistory->method('getForMediaItem')->willReturn(null);

        $sync = $this->createSync(watchHistory: $watchHistory);

        $result = $sync->syncPhlixToTrakt('media-id', 'now', 0, null);

        $this->assertFalse($result);
    }

    public function testSyncPhlixToTraktReturnsFalseWhenBelow90Percent(): void
    {
        $watchHistory = $this->createMock(WatchHistory::class);
        $watchHistory->method('getForMediaItem')
            ->willReturn(['progress_percent' => 50.0]);

        $sync = $this->createSync(watchHistory: $watchHistory);

        $result = $sync->syncPhlixToTrakt('media-id', 'now', 0, null);

        $this->assertFalse($result);
    }

    public function testSyncPhlixToTraktReturnsTrueOnSuccess(): void
    {
        $api = $this->createMock(TraktApi::class);
        $api->expects($this->once())
            ->method('addToHistory')
            ->willReturn(['added' => ['movies' => 1]]);

        $watchHistory = $this->createMock(WatchHistory::class);
        $watchHistory->method('getForMediaItem')
            ->willReturn([
                'media_name' => 'Test Movie',
                'media_type' => 'movie',
                'metadata' => [],
                'progress_percent' => 95.0,
            ]);

        $sync = $this->createSync($api, $watchHistory);

        $result = $sync->syncPhlixToTrakt('media-id', '2024-01-15T10:30:00Z', 0, null);

        $this->assertTrue($result);
    }

    public function testSyncPhlixToTraktReturnsFalseWhenApiThrows(): void
    {
        $api = $this->createMock(TraktApi::class);
        $api->method('addToHistory')
            ->willThrowException(new \Phlix\Plugins\Scrobbler\Trakt\TraktApiException('API error'));

        $watchHistory = $this->createMock(WatchHistory::class);
        $watchHistory->method('getForMediaItem')
            ->willReturn([
                'media_name' => 'Test Movie',
                'media_type' => 'movie',
                'metadata' => [],
                'progress_percent' => 95.0,
            ]);

        $sync = $this->createSync($api, $watchHistory);

        $result = $sync->syncPhlixToTrakt('media-id', 'now', 0, null);

        $this->assertFalse($result);
    }

    public function testSyncPhlixToTraktBuildsMediaItemFromHistoryEntry(): void
    {
        $api = $this->createMock(TraktApi::class);
        $api->expects($this->once())
            ->method('addToHistory')
            ->willReturn(['added' => ['movies' => 1]]);

        $watchHistory = $this->createMock(WatchHistory::class);
        $watchHistory->method('getForMediaItem')
            ->willReturn([
                'media_name' => 'My Custom Movie',
                'media_type' => 'movie',
                'metadata' => ['tmdb_id' => 42],
                'progress_percent' => 95.0,
            ]);

        $sync = $this->createSync($api, $watchHistory);

        $result = $sync->syncPhlixToTrakt('my-media-id', '2024-06-15T12:00:00Z', 0, null);

        $this->assertTrue($result);
    }

    /**
     * PG-129: when addToHistory reports the hasAnyId skip, the push did NOT
     * happen — syncPhlixToTrakt must return false. On the parent the doomed
     * POST 400'd into the exception arm (also false), so this pins that the
     * cheap skip preserves the observable truth instead of silently
     * reporting a successful push.
     */
    public function testSyncPhlixToTraktReturnsFalseWhenHistoryPushIsSkipped(): void
    {
        $api = $this->createMock(TraktApi::class);
        $api->method('addToHistory')->willReturn(['added' => false, 'skipped' => true]);

        $watchHistory = $this->createMock(WatchHistory::class);
        $watchHistory->method('getForMediaItem')->willReturn([
            'media_name' => 'No Ids Movie',
            'media_type' => 'movie',
            'metadata' => [],
            'progress_percent' => 95.0,
        ]);

        $sync = $this->createSync($api, $watchHistory);

        $this->assertFalse($sync->syncPhlixToTrakt('my-media-id', '2024-06-15T12:00:00Z', 0, null));
    }

    /**
     * PG-127: the /users/{id}/history shape carries no `runtime` on its
     * embedded objects, so every completion pulled used to hit the
     * preserve-skip and silently import nothing. The sync must size the
     * completed row from local metadata when Trakt supplies no runtime.
     */
    public function testSyncTraktToPhlixSizesCompletedRowFromLocalMetadataWhenTraktCarriesNoRuntime(): void
    {
        $traktHistory = [
            [
                'movie' => [
                    'ids' => ['tmdb' => 123],
                ],
                'watched_at' => '2024-03-01T10:00:00Z',
            ],
        ];

        $api = $this->createMock(TraktApi::class);
        $api->method('getWatchedHistory')->willReturn($traktHistory);

        $db = new TestableConnection();
        $db->setResults([
            [['id' => 'local-id-x']], // external-id lookup
            [['metadata_json' => '{"duration_seconds":5400}']], // PG-127 sizing read
        ]);

        $watchHistory = $this->getMockBuilder(WatchHistory::class)
            ->onlyMethods(['getForMediaItem', 'updateProgress'])
            ->getMock();
        $watchHistory->method('getForMediaItem')->willReturn(null);

        $watchHistory->expects($this->once())->method('updateProgress')->with(
            'default',
            'local-id-x',
            5400 * WatchHistory::TICKS_PER_SECOND,
            5400 * WatchHistory::TICKS_PER_SECOND,
            WatchHistory::STATUS_COMPLETED,
        );

        $sync = $this->createSync($api, $watchHistory, null, $db);

        $this->assertSame(1, $sync->syncTraktToPhlix('default'));
    }

    /**
     * PG-127: a sub-threshold local row that already carries duration_ticks
     * is the cheapest trusted sizing source — no metadata query needed.
     */
    public function testSyncTraktToPhlixReusesExistingRowDurationTicksWhenTraktCarriesNoRuntime(): void
    {
        $traktHistory = [
            [
                'movie' => [
                    'ids' => ['tmdb' => 123],
                ],
                'watched_at' => '2024-03-02T10:00:00Z',
            ],
        ];

        $api = $this->createMock(TraktApi::class);
        $api->method('getWatchedHistory')->willReturn($traktHistory);

        $db = new TestableConnection();
        $db->setResults([[['id' => 'local-id-123']]]); // external-id lookup only

        $watchHistory = $this->getMockBuilder(WatchHistory::class)
            ->onlyMethods(['getForMediaItem', 'updateProgress'])
            ->getMock();
        $watchHistory->method('getForMediaItem')
            ->willReturn(['progress_percent' => 50.0, 'duration_ticks' => 45000000]);

        $watchHistory->expects($this->once())->method('updateProgress')->with(
            'default',
            'local-id-123',
            45000000,
            45000000,
            WatchHistory::STATUS_COMPLETED,
        );

        $sync = $this->createSync($api, $watchHistory, null, $db);

        $this->assertSame(1, $sync->syncTraktToPhlix('default'));
        $this->assertCount(1, $db->getCalls(), 'existing row duration must not trigger a metadata query');
    }

    /**
     * PG-127 non-destructive law pin: when NEITHER Trakt nor local metadata
     * carries a positive duration, the preserve-skip stays — a 0/0 write
     * would zero an existing row while marking it completed.
     */
    public function testSyncTraktToPhlixPreservesLocalStateWhenNeitherSideHasDuration(): void
    {
        $traktHistory = [
            [
                'movie' => [
                    'ids' => ['tmdb' => 123],
                ],
                'watched_at' => '2024-03-03T10:00:00Z',
            ],
        ];

        $api = $this->createMock(TraktApi::class);
        $api->method('getWatchedHistory')->willReturn($traktHistory);

        $db = new TestableConnection();
        $db->setResults([
            [['id' => 'local-id-123']],
            [['metadata_json' => null]],
        ]);

        $watchHistory = $this->getMockBuilder(WatchHistory::class)
            ->onlyMethods(['getForMediaItem', 'updateProgress'])
            ->getMock();
        $watchHistory->method('getForMediaItem')->willReturn(null);
        $watchHistory->expects($this->never())->method('updateProgress');

        $sync = $this->createSync($api, $watchHistory, null, $db);

        $this->assertSame(0, $sync->syncTraktToPhlix('default'));
    }

    // --- credential law on the push surfaces (PG-135) ------------------------

    /**
     * History PULL rethrows a 401 so TraktPlugin's recovery (one gated
     * refresh, one re-pull) can run. The PUSH surfaces carry the same
     * credential truth: on the parent every push catch was a blanket
     * TraktApiException arm that swallowed the auth subclass into a silent
     * false — a dead token then died unattended and every later push of
     * every user failed identically with no recovery path attempted.
     */
    public function testSyncPhlixToTraktRethrowsAuthenticationFailure(): void
    {
        $api = $this->createMock(TraktApi::class);
        $api->method('addToHistory')->willThrowException(
            new \Phlix\Plugins\Scrobbler\Trakt\TraktAuthenticationException('Expired token', 401)
        );

        $watchHistory = $this->createMock(WatchHistory::class);
        $watchHistory->method('getForMediaItem')->willReturn([
            'media_name' => 'Test Movie',
            'media_type' => 'movie',
            'metadata' => ['tmdb_id' => 42],
            'progress_percent' => 95.0,
        ]);

        $sync = $this->createSync($api, $watchHistory);

        $this->expectException(\Phlix\Plugins\Scrobbler\Trakt\TraktAuthenticationException::class);
        $sync->syncPhlixToTrakt('media-id', '2024-01-15T10:30:00Z', 0, null);
    }

    public function testSyncRatingsPhlixToTraktRethrowsAuthenticationFailure(): void
    {
        $api = $this->createMock(TraktApi::class);
        $api->method('addRating')->willThrowException(
            new \Phlix\Plugins\Scrobbler\Trakt\TraktAuthenticationException('Expired token', 401)
        );

        $watchHistory = $this->createMock(WatchHistory::class);
        $watchHistory->method('getForMediaItem')->willReturn([
            'media_name' => 'Test Movie',
            'media_type' => 'movie',
            'metadata' => ['tmdb_id' => 42],
            'progress_percent' => 95.0,
        ]);

        $sync = $this->createSync($api, $watchHistory);

        $this->expectException(\Phlix\Plugins\Scrobbler\Trakt\TraktAuthenticationException::class);
        $this->assertFalse($sync->syncRatingsPhlixToTrakt('media-id', 8));
    }

    /**
     * RATINGS PULL is a pull: it must carry the history pull's law verbatim —
     * auth errors rethrow to the caller's recovery, only transient API
     * failures degrade to 0. The parent caught (and swallowed) auth inside a
     * blanket TraktApiException arm.
     */
    public function testSyncRatingsTraktToPhlixRethrowsAuthenticationFailure(): void
    {
        $api = $this->createMock(TraktApi::class);
        $api->method('getRatings')->willThrowException(
            new \Phlix\Plugins\Scrobbler\Trakt\TraktAuthenticationException('Expired token', 401)
        );

        $sync = $this->createSync($api);

        $this->expectException(\Phlix\Plugins\Scrobbler\Trakt\TraktAuthenticationException::class);
        $sync->syncRatingsTraktToPhlix('default');
    }

    /**
     * PG-135 (secaudit#9): the last_watched_at string flows from stored
     * history into `new DateTimeImmutable(...)` unguarded — any malformed
     * value THROWS, and on the parent the throw escaped the intended catch
     * arm (which only covers TraktApiException) to fail the whole push.
     * The parse must degrade like the pull side's parseWatchedAt(): warn and
     * fall back to now, still pushing the completion.
     */
    public function testSyncPhlixToTraktFallsBackToNowOnMalformedLastWatchedAt(): void
    {
        $api = $this->createMock(TraktApi::class);
        $api->expects($this->once())
            ->method('addToHistory')
            ->willReturn(['added' => ['movies' => 1]]);

        $watchHistory = $this->createMock(WatchHistory::class);
        $watchHistory->method('getForMediaItem')->willReturn([
            'media_name' => 'Test Movie',
            'media_type' => 'movie',
            'metadata' => ['tmdb_id' => 42],
            'progress_percent' => 95.0,
        ]);

        $sync = $this->createSync($api, $watchHistory);

        $this->assertTrue($sync->syncPhlixToTrakt('media-id', 'not-a-date', 0, null));
    }
}
