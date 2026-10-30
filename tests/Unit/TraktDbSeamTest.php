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
 * Guards the DB seam between this plugin and the host connection class.
 *
 * The 2026-10-28 senior-PHP review (trakt__srphp C1) found that
 * TraktHistorySync called `db->real_escapeString()` — a method that does NOT
 * exist on the production `Workerman\MySQL\Connection` (upstream walkor/mysql
 * is PDO-based: values travel only through `query($sql, $params)` bound
 * placeholders; it exposes no escape_* primitive at all). The unit stub had
 * fabricated the method, so 245 tests stayed green while every Trakt→Phlix
 * pull sync fataled in production.
 *
 * These tests pin the law at three levels:
 *  1. a source-wide method allow-list (kills any fabricated host method);
 *  2. the external-ID lookup binding the RAW value for JSON_EXTRACT equality
 *     and a LIKE-metacharacter-neutralised pattern for the LIKE fallback;
 *  3. the title/year fallback binding a LIKE-metacharacter-neutralised
 *     pattern (review trakt__secaudit m7: remote titles carrying %/_ could
 *     otherwise broaden the fuzzy match and bind the wrong local item).
 */
final class TraktDbSeamTest extends TestCase
{
    /**
     * The exact public surface of upstream Workerman\MySQL\Connection that
     * plugin code may call (verified against upstream src/Connection.php:
     * query(), beginTrans(), commitTrans(), rollBackTrans()).
     */
    private const ALLOWED_DB_METHODS = ['query', 'beginTrans', 'commitTrans', 'rollBackTrans'];

    public function testThePluginOnlyCallsDbMethodsTheUpstreamConnectionProvides(): void
    {
        $files = glob(dirname(__DIR__, 2) . '/src/*.php');
        $this->assertNotEmpty($files, 'No plugin sources found to scan.');

        $unknown = [];
        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            preg_match_all('/\$this->db->(\w+)\s*\(/', $source, $matches);
            foreach ($matches[1] as $method) {
                if (!in_array($method, self::ALLOWED_DB_METHODS, true)) {
                    $unknown[] = basename($file) . ': $this->db->' . $method . '()';
                }
            }
        }

        $this->assertSame(
            [],
            array_values(array_unique($unknown)),
            'The plugin calls methods on the injected \Workerman\MySQL\Connection that the'
            . ' upstream production class does not provide. It binds values through'
            . ' query($sql, $params) PDO placeholders and exposes no escaping primitive.'
        );
    }

    public function testExternalIdLookupBindsRawEqualityValueAndNeutralisedLikePattern(): void
    {
        $db = new TestableConnection();
        // JSON_EXTRACT lookup misses so the LIKE fallback also executes.
        $db->setResults([[], [['id' => 'resolved-by-like']]]);
        $sync = $this->createSync($db);

        $hostileId = "tt1'2\"3\\\\4";
        $result = $this->invokePrivate($sync, 'findMediaItemIdByExternalId', ['imdb', $hostileId]);

        $this->assertSame('resolved-by-like', $result);

        $calls = $db->getCalls();
        $this->assertCount(2, $calls);

        // Leg 1: JSON_EXTRACT binds the path and the RAW external id.
        $this->assertStringContainsString('JSON_EXTRACT', $calls[0]['sql']);
        $this->assertSame(['$.imdb_id', $hostileId], $calls[0]['params']);

        // Leg 2: the LIKE fallback binds a pattern whose metacharacters are
        // neutralised — the structural `_` before `id` and any %, _ or \ from
        // the payload are backslash-escaped, so the fallback can only ever
        // match the literal JSON key/value. The value is still a bound
        // parameter (the host class exposes no SQL escaping primitive).
        $this->assertStringContainsString('LIKE', $calls[1]['sql']);
        $this->assertSame(
            // Same $hostileId with its backslash doubled by the LIKE escaper.
            ['%"imdb\\_id":"' . "tt1'2\"3\\\\\\\\4" . '"%'],
            $calls[1]['params']
        );
    }

    public function testTitleYearFallbackEscapesLikeWildcardsInTheTitle(): void
    {
        $db = new TestableConnection();
        $db->setResults([[['id' => 'resolved-by-title']]]);
        $sync = $this->createSync($db);

        $hostileTitle = '100%_done \\done';
        $result = $this->invokePrivate($sync, 'findMediaItemIdByTitleYear', [$hostileTitle, 2024, 'movie']);

        $this->assertSame('resolved-by-title', $result);

        $calls = $db->getCalls();
        $this->assertCount(1, $calls);
        $this->assertStringContainsString('name LIKE', $calls[0]['sql']);
        // % _ and \ from the remote title are all backslash-escaped inside the
        // pattern; the surrounding % wildcards of the fuzzy match stay literal.
        $this->assertSame(
            ['%100\\%\\_done \\\\done%', '%"year":"2024"%', 'movie'],
            $calls[0]['params']
        );
    }

    public function testLikeWildcardsAreEscapedBeforePercentAndUnderscore(): void
    {
        // Order matters: doubling backslashes first, then escaping wildcards,
        // so an original '\' can never synthesise a new escape sequence.
        $escaped = $this->escapeViaProductParser('a\\%b_c');

        $this->assertSame('a\\\\\\%b\\_c', $escaped);
    }

    /**
     * Invoke the product's private LIKE-escaping boundary parser.
     */
    private function escapeViaProductParser(string $value): string
    {
        $method = new \ReflectionMethod(TraktHistorySync::class, 'escapeLikeWildcards');
        $method->setAccessible(true);

        /** @var string $result */
        $result = $method->invoke(null, $value);

        return $result;
    }

    /**
     * @param array<int, mixed> $args
     */
    private function invokePrivate(TraktHistorySync $sync, string $method, array $args): mixed
    {
        $reflection = new \ReflectionMethod($sync, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($sync, $args);
    }

    private function createSync(TestableConnection $db): TraktHistorySync
    {
        return new TraktHistorySync(
            $this->createMock(TraktApi::class),
            $this->createMock(WatchHistory::class),
            new TraktSettings(
                accessToken: 'test-access',
                refreshToken: 'test-refresh',
                username: 'testuser'
            ),
            $db,
            new NullLogger()
        );
    }
}
