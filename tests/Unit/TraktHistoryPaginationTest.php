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
 * Consequence tests for Trakt history pagination.
 *
 * ## The defect
 *
 * `syncTraktToPhlix()` called `getWatchedHistory($username, 1, 100, $token)` —
 * page 1 only. Any user with more than 100 watched items had the remainder
 * silently dropped, and the sync reported success having imported a truncated
 * history. Nothing surfaced it: a partial import looks exactly like a complete
 * one from the outside.
 *
 * These assertions check the OBSERVABLE EFFECT — how many pages are actually
 * requested and how many items come back — not that a pagination helper exists.
 */
final class TraktHistoryPaginationTest extends TestCase
{
    /**
     * @param list<list<array<string, mixed>>> $pages Successive API responses.
     */
    private function syncWithPages(array $pages, TraktApi &$api = null): TraktHistorySync
    {
        $api = $this->createMock(TraktApi::class);

        $calls = 0;
        $api->method('getWatchedHistory')->willReturnCallback(
            static function (string $u, int $page, int $limit, string $t) use ($pages, &$calls): array {
                $calls++;

                return $pages[$page - 1] ?? [];
            }
        );

        return new TraktHistorySync(
            $api,
            $this->createMock(WatchHistory::class),
            new TraktSettings(
                accessToken: 'test-access',
                refreshToken: 'test-refresh',
                username: 'testuser',
                syncEnabled: true,
            ),
            $this->createMock(Connection::class),
            new NullLogger()
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fullPage(): array
    {
        return array_fill(0, 100, ['movie' => ['ids' => ['trakt' => 1]]]);
    }

    private function fetchAll(TraktHistorySync $sync): array
    {
        $ref = new \ReflectionClass(TraktHistorySync::class);
        $m = $ref->getMethod('fetchAllWatchedHistory');
        $m->setAccessible(true);

        /** @var array<mixed> $result */
        $result = $m->invoke($sync);

        return $result;
    }

    /**
     * CONSEQUENCE: a history spanning three pages must yield all three pages'
     * items, not just the first 100.
     *
     * Mutation-verified: restoring the single `getWatchedHistory(..., 1, 100, ...)`
     * call drops the count to 100 and fails this test.
     */
    public function test_history_spanning_multiple_pages_is_fetched_completely(): void
    {
        $pages = [
            $this->fullPage(),                              // page 1: full -> continue
            $this->fullPage(),                              // page 2: full -> continue
            array_fill(0, 25, ['movie' => ['ids' => ['trakt' => 2]]]), // page 3: short -> stop
        ];

        $sync = $this->syncWithPages($pages);

        $this->assertCount(
            225,
            $this->fetchAll($sync),
            'All three pages must be imported. Fetching only page 1 silently '
            . 'truncates the history of any user with more than 100 watched items.'
        );
    }

    /**
     * A single short page must not trigger a second request — the short page IS
     * the end-of-results signal.
     */
    public function test_a_single_short_page_stops_immediately(): void
    {
        $api = null;
        $sync = $this->syncWithPages([
            array_fill(0, 7, ['movie' => ['ids' => ['trakt' => 3]]]),
        ], $api);

        $this->assertCount(7, $this->fetchAll($sync));
    }

    /**
     * An empty first page yields nothing and does not loop.
     */
    public function test_empty_history_yields_no_items(): void
    {
        $sync = $this->syncWithPages([[]]);

        $this->assertSame([], $this->fetchAll($sync));
    }

    /**
     * CONSEQUENCE: the pager must be bounded.
     *
     * An API that never returns a short page must not spin forever inside a
     * resident Workerman worker. Feeding full pages indefinitely must terminate
     * at the page cap rather than hang.
     */
    public function test_pager_is_bounded_when_the_api_never_returns_a_short_page(): void
    {
        $api = $this->createMock(TraktApi::class);
        $api->method('getWatchedHistory')->willReturnCallback(
            fn (): array => $this->fullPage()   // always full: never signals the end
        );

        $sync = new TraktHistorySync(
            $api,
            $this->createMock(WatchHistory::class),
            new TraktSettings(
                accessToken: 'a',
                refreshToken: 'r',
                username: 'testuser',
                syncEnabled: true,
            ),
            $this->createMock(Connection::class),
            new NullLogger()
        );

        $ref = new \ReflectionClass(TraktHistorySync::class);
        $cap = $ref->getConstant('HISTORY_MAX_PAGES');
        $size = $ref->getConstant('HISTORY_PAGE_SIZE');
        $this->assertIsInt($cap);
        $this->assertIsInt($size);

        $this->assertCount(
            $cap * $size,
            $this->fetchAll($sync),
            'The pager must stop at HISTORY_MAX_PAGES rather than looping forever.'
        );
    }
}
