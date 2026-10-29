<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins\Scrobbler\Trakt;

use Phlix\Auth\WatchHistory;
use Phlix\Plugins\Scrobbler\Trakt\TraktApi;
use Phlix\Plugins\Scrobbler\Trakt\TraktHistorySync;
use Phlix\Plugins\Scrobbler\Trakt\TraktSettings;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Workerman\MySQL\TestableConnection;

/**
 * C06 regression (review trakt__srphp row 42 — CRITICAL): rating pull from
 * Trakt silently discarded the rating and destructively rewrote watch_history.
 *
 * Pre-fix updateLocalRating() called
 *   WatchHistory::updateProgress($profileId, $mediaItemId, 0, 0, STATUS_COMPLETED, $rating)
 * The host method takes five parameters — the 6th (the rating itself) was
 * silently dropped, while position=0/duration=0/completed zeroed any existing
 * row's resume position, duration and progress: user data loss for every
 * rated item, and no rating ever persisted.
 *
 * Post-fix law pinned here:
 *  1. The rating lands in the host's canonical rating column — the exact
 *     UserItemDataRepository::setRating() upsert into user_item_data, whose
 *     ON DUPLICATE arm touches ONLY the rating column, so companion
 *     per-item data (favorite, like_level, watched) on an existing row
 *     survives.
 *  2. The rating path performs NO watch_history write at all — an in-progress
 *     local row is left exactly as it was.
 *  3. When the sync profile's owning user cannot be resolved, nothing is
 *     written and the item is not counted (no invented identity, no
 *     destructive fallback).
 *  4. The sibling defect from the same review section — the history pull
 *     writing 0/0 when Trakt supplies no runtime — preserves local progress
 *     instead.
 */
final class TraktRatingPullPersistenceTest extends TestCase
{
    private const PROFILE_ID = 'profile-uuid-1';
    private const USER_ID = 'user-uuid-1';
    private const MEDIA_ITEM_ID = 'local-id-1';

    private function configuredSettings(): TraktSettings
    {
        return new TraktSettings(
            accessToken: 'a',
            refreshToken: 'r',
            username: 'testuser',
            syncEnabled: true,
        );
    }

    private function createSync(
        TraktApi $api,
        WatchHistory $watchHistory,
        TestableConnection $db,
    ): TraktHistorySync {
        return new TraktHistorySync(
            $api,
            $watchHistory,
            $this->configuredSettings(),
            $db,
            new NullLogger(),
        );
    }

    /**
     * One Trakt rating entry whose movie resolves by tmdb external id.
     *
     * @return array<string, mixed>
     */
    private function ratingEntry(int $rating): array
    {
        return [
            'movie' => ['ids' => ['tmdb' => 123], 'title' => 'Movie 1', 'year' => 2024],
            'rating' => $rating,
            'rated_at' => '2024-01-01T00:00:00Z',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function inProgressHistoryRow(): array
    {
        return [
            'progress_percent' => 45.0,
            'position_ticks' => 45 * WatchHistory::TICKS_PER_SECOND,
            'duration_ticks' => 100 * WatchHistory::TICKS_PER_SECOND,
            'playback_status' => WatchHistory::STATUS_IN_PROGRESS,
        ];
    }

    public function testPulledRatingIsPersistedIntoCanonicalUserItemDataColumn(): void
    {
        $api = $this->createMock(TraktApi::class);
        $api->method('getRatings')->willReturn([$this->ratingEntry(8)]);

        $db = new TestableConnection();
        $db->setResults([
            [['id' => self::MEDIA_ITEM_ID]],  // media_items external-id lookup
            [['user_id' => self::USER_ID]],   // user_profiles owner resolution
            [],                               // user_item_data upsert
        ]);

        $sync = $this->createSync($api, $this->createMock(WatchHistory::class), $db);

        $this->assertSame(1, $sync->syncRatingsTraktToPhlix(self::PROFILE_ID));

        $calls = $db->getCalls();
        $this->assertCount(3, $calls);

        // Ownership is resolved by bound lookup, never guessed.
        $this->assertStringContainsString('FROM user_profiles', $calls[1]['sql']);
        $this->assertSame([self::PROFILE_ID], $calls[1]['params']);

        // The write mirrors the host's UserItemDataRepository::setRating().
        $this->assertStringContainsString(
            'INSERT INTO user_item_data (user_id, profile_id, item_id, rating)',
            $calls[2]['sql']
        );
        $this->assertStringContainsString(
            'ON DUPLICATE KEY UPDATE rating = VALUES(rating)',
            $calls[2]['sql']
        );
        $this->assertSame(
            [self::USER_ID, self::PROFILE_ID, self::MEDIA_ITEM_ID, 8],
            $calls[2]['params']
        );
    }

    public function testRatingSyncNeverRewritesWatchHistoryOfInProgressItem(): void
    {
        $api = $this->createMock(TraktApi::class);
        $api->method('getRatings')->willReturn([$this->ratingEntry(9)]);

        $db = new TestableConnection();
        $db->setResults([
            [['id' => self::MEDIA_ITEM_ID]],
            [['user_id' => self::USER_ID]],
            [],
        ]);

        $watchHistory = $this->getMockBuilder(WatchHistory::class)
            ->onlyMethods(['getForMediaItem', 'updateProgress'])
            ->getMock();
        // A mid-watch row is precisely what the pre-fix code zeroed by
        // writing position=0/duration=0/status=completed.
        $watchHistory->method('getForMediaItem')->willReturn($this->inProgressHistoryRow());
        $watchHistory->expects($this->never())->method('updateProgress');

        $sync = $this->createSync($api, $watchHistory, $db);

        $this->assertSame(1, $sync->syncRatingsTraktToPhlix(self::PROFILE_ID));

        // The rating path issues no watch_history statement of any shape.
        foreach ($db->getCalls() as $call) {
            $this->assertStringNotContainsStringIgnoringCase('watch_history', $call['sql']);
        }

        // The ON DUPLICATE arm updates ONLY the rating column, so an existing
        // row's favorite / like_level / watched companions survive (host law,
        // UserItemDataRepository::setRating()).
        $upsertSql = $db->getCalls()[2]['sql'];
        $this->assertMatchesRegularExpression(
            '/ON DUPLICATE KEY UPDATE\s+rating\s*=\s*VALUES\(rating\)\s*$/i',
            $upsertSql
        );
    }

    public function testUnresolvableProfileOwnerWritesNothingAndIsNotCounted(): void
    {
        $api = $this->createMock(TraktApi::class);
        $api->method('getRatings')->willReturn([$this->ratingEntry(7)]);

        $db = new TestableConnection();
        $db->setResults([
            [['id' => self::MEDIA_ITEM_ID]],  // external-id lookup succeeds
            [],                               // no user_profiles row owns the profile
        ]);

        $watchHistory = $this->getMockBuilder(WatchHistory::class)
            ->onlyMethods(['getForMediaItem', 'updateProgress'])
            ->getMock();
        // Pre-fix fell through to the destructive updateProgress here; the
        // fix must skip instead of inventing an identity or wiping progress.
        $watchHistory->expects($this->never())->method('updateProgress');

        $sync = $this->createSync($api, $watchHistory, $db);

        $this->assertSame(0, $sync->syncRatingsTraktToPhlix(self::PROFILE_ID));

        $calls = $db->getCalls();
        $this->assertCount(2, $calls);
        foreach ($calls as $call) {
            $this->assertStringNotContainsStringIgnoringCase('INSERT INTO user_item_data', $call['sql']);
        }
    }

    public function testHistoryEntryWithoutRuntimePreservesLocalInProgressRow(): void
    {
        // Same review section's second destructive site: the history pull
        // passed extractDurationTicks()=0 straight into updateProgress.
        $traktHistory = [[
            'movie' => ['ids' => ['tmdb' => 123]],
            'watched_at' => '2024-01-15T10:30:00Z',
            // No runtime anywhere — extractDurationTicks() returns 0.
        ]];

        $api = $this->createMock(TraktApi::class);
        $api->method('getWatchedHistory')->willReturn($traktHistory);

        $db = new TestableConnection();
        $db->setResults([
            [['id' => self::MEDIA_ITEM_ID]],  // external-id lookup
            [],                               // fetchAllWatchedHistory short-page break: no further queries
        ]);

        $watchHistory = $this->getMockBuilder(WatchHistory::class)
            ->onlyMethods(['getForMediaItem', 'updateProgress'])
            ->getMock();
        $watchHistory->method('getForMediaItem')->willReturn($this->inProgressHistoryRow());
        $watchHistory->expects($this->never())->method('updateProgress');

        $sync = $this->createSync($api, $watchHistory, $db);

        $this->assertSame(0, $sync->syncTraktToPhlix(self::PROFILE_ID));
    }

    public function testHistoryEntryWithRuntimeStillCompletesItem(): void
    {
        // Positive control: the legitimate 0→completed write path keeps its
        // exact five-argument host contract (with() pins arity and values —
        // a drifted call reddens this test).
        $traktHistory = [[
            'movie' => ['ids' => ['tmdb' => 123]],
            'watched_at' => '2024-01-15T10:30:00Z',
            'runtime' => 120,
        ]];

        $api = $this->createMock(TraktApi::class);
        $api->method('getWatchedHistory')->willReturn($traktHistory);

        $db = new TestableConnection();
        $db->setResults([
            [['id' => self::MEDIA_ITEM_ID]],
        ]);

        $ticks = 120 * WatchHistory::TICKS_PER_SECOND;

        $watchHistory = $this->getMockBuilder(WatchHistory::class)
            ->onlyMethods(['getForMediaItem', 'updateProgress'])
            ->getMock();
        $watchHistory->method('getForMediaItem')->willReturn(null);
        $watchHistory->expects($this->once())->method('updateProgress')->with(
            $this->identicalTo(self::PROFILE_ID),
            $this->identicalTo(self::MEDIA_ITEM_ID),
            $this->identicalTo($ticks),
            $this->identicalTo($ticks),
            $this->identicalTo(WatchHistory::STATUS_COMPLETED),
        );

        $sync = $this->createSync($api, $watchHistory, $db);

        $this->assertSame(1, $sync->syncTraktToPhlix(self::PROFILE_ID));
    }
}
