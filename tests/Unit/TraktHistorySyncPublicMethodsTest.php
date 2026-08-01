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
}
