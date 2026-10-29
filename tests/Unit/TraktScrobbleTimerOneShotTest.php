<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins\Scrobbler\Trakt;

use Phlix\Plugins\Scrobbler\Trakt\TraktPlugin;
use Phlix\Plugins\Scrobbler\Trakt\TraktSettings;
use Phlix\Shared\Events\Playback\PlaybackPaused;
use Phlix\Shared\Events\Playback\PlaybackResumed;
use Phlix\Shared\Events\Playback\PlaybackStarted;
use Phlix\Shared\Events\Playback\PlaybackStopped;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Workerman\Timer;

/**
 * Regression pins for review trakt__srphp C3 (critical row 43).
 *
 * ## The defect
 *
 * The scrobble deferral was written as `Workerman\Timer::add(0, $cb)` intending
 * "run once next tick". Workerman timers are PERSISTENT unless the 4th argument
 * is `false` (upstream `src/Timer.php: add(float $timeInterval, callable $func,
 * ?array $args = [], bool $persistent = true)`), and an interval of 0 is legal —
 * on the event loop it routes to `Select::repeat(0, …)`, which re-arms at
 * `now + 0` every iteration. Each playback start/pause/resume/stop therefore
 * installed an IMMORTAL timer POSTing the same scrobble at loop rate: pinned
 * CPU, starved connections, and unbounded hammering of Trakt's `/scrobble/*`
 * endpoints (the rate-limit / app-BAN posture this plugin exists to avoid).
 *
 * ## How these tests see the registration
 *
 * `tests/stubs/Workerman/Timer.php` mirrors the real signature — including the
 * `persistent = true` default, which is the defect shape — records every arm,
 * fires ONE-SHOT arms immediately (the unit-test equivalent of the next tick)
 * and never fires recurring arms. Before the stub existed, `class_exists()`
 * was false in this plugin's own vendor tree, so every test took the
 * synchronous fallback and the arguments were never exercised at all.
 */
final class TraktScrobbleTimerOneShotTest extends TestCase
{
    protected function setUp(): void
    {
        Timer::reset();
    }

    /**
     * CONSEQUENCE: each playback event arms EXACTLY ONE timer and it must be a
     * one-shot (persistent === false) at interval 0.
     *
     * Mutation-verified: restoring `Timer::add(0, $cb)` without the trailing
     * `false` (i.e. the shipped defect) records persistent === true and reddens
     * every dataset below; the stub also then refuses to fire the arm, so the
     * scrobble-count assertion drops to 0 — the pre-fix shape cannot pass.
     */
    public function testPlaybackEventsArmOneShotTimers(): void
    {
        $cases = [
            [
                new PlaybackStarted('sess-1', 'user-1', 'mi-1', 'dev-1', 0),
                'onPlaybackStarted',
                'scrobbleStartCalls',
            ],
            [
                new PlaybackPaused('sess-1', 'user-1', 'mi-1', 'dev-1', 5_000_000),
                'onPlaybackPaused',
                'scrobblePauseCalls',
            ],
            [
                new PlaybackResumed('sess-1', 'user-1', 'mi-1', 'dev-1', 5_000_000),
                'onPlaybackResumed',
                'scrobbleStartCalls',
            ],
            [
                new PlaybackStopped('sess-1', 'user-1', 'mi-1', 'dev-1', 10_000_000, false),
                'onPlaybackStopped',
                'scrobbleStopCalls',
            ],
        ];

        foreach ($cases as [$event, $handler, $counter]) {
            $api = new FakeTraktApi();
            $plugin = $this->wiredPlugin($api);
            Timer::reset(); // Drop the recurring sync arm from onEnable(); see only this event's arm.

            $plugin->{$handler}($event);

            $this->assertCount(1, Timer::$arms, "{$handler} must arm exactly one timer");
            $this->assertSame(
                0.0,
                Timer::$arms[0]['interval'],
                "{$handler} must defer to the next tick (interval 0)"
            );
            $this->assertFalse(
                Timer::$arms[0]['persistent'],
                "{$handler} armed a PERSISTENT timer: an interval-0 repeat is the "
                . 'scrobble-spammer defect (review trakt__srphp C3).'
            );
            $this->assertSame(
                1,
                $api->{$counter},
                "{$handler} must submit its scrobble exactly once"
            );
        }
    }

    /**
     * CONSEQUENCE: a burst of playback events must not multiply submissions.
     *
     * Three starts arm three one-shots and produce exactly three scrobbles.
     * Under the shipped defect the arms were persistent, so the real worker
     * replayed every captured closure forever; the stub cannot model infinite
     * firing, which is precisely why the registration flag (not the invocation
     * count alone) is the contract pinned here.
     */
    public function testRepeatedPlaybackEventsArmOneShotEachAndRunOnceEach(): void
    {
        $api = new FakeTraktApi();
        $plugin = $this->wiredPlugin($api);
        Timer::reset();

        $event = new PlaybackStarted('sess-1', 'user-1', 'mi-1', 'dev-1', 0);
        $plugin->onPlaybackStarted($event);
        $plugin->onPlaybackStarted($event);
        $plugin->onPlaybackStarted($event);

        $this->assertCount(3, Timer::$arms);
        $this->assertSame([false, false, false], array_column(Timer::$arms, 'persistent'));
        $this->assertSame(3, $api->scrobbleStartCalls);
    }

    /**
     * SCOPE GUARD: the genuine recurring schedule must STAY recurring.
     *
     * `schedulePeriodicSync()` arms the Trakt→Phlix pull every
     * `sync_interval_minutes` (default 30 → 1800s); converting it to a one-shot
     * would silently kill history sync — the opposite failure of C3. Playback
     * traffic must never add to the persistent set.
     */
    public function testPeriodicSyncTimerStaysPersistentAndScrobblesAddNone(): void
    {
        $api = new FakeTraktApi();
        $plugin = $this->wiredPlugin($api);

        $recurring = array_values(array_filter(Timer::$arms, static fn(array $arm): bool => $arm['persistent']));
        $this->assertCount(1, $recurring, 'onEnable must arm exactly one recurring sync timer');
        $this->assertSame(1800.0, $recurring[0]['interval']);
        $this->assertSame(0, $api->scrobbleStartCalls, 'arming the recurring timer must not scrobble');

        $plugin->onPlaybackStarted(new PlaybackStarted('sess-1', 'user-1', 'mi-1', 'dev-1', 0));
        $plugin->onPlaybackStopped(new PlaybackStopped('sess-1', 'user-1', 'mi-1', 'dev-1', 10_000_000, false));

        $recurringAfter = array_values(array_filter(Timer::$arms, static fn(array $arm): bool => $arm['persistent']));
        $this->assertCount(1, $recurringAfter, 'playback events must not install persistent timers');
        $this->assertCount(3, Timer::$arms, 'one recurring sync arm + two one-shot scrobble arms');
    }

    /**
     * CALL-SITE CENSUS: the only persistent-capable arm left in the plugin is
     * the periodic pull; every scrobble arm goes through the one-shot helper.
     *
     * Source-inspection companion to the behavioural pins above (the house
     * pattern in TraktSyncIntervalTest): it keeps a new call site that bypasses
     * scheduleScrobbleTask() from re-introducing the loop unnoticed.
     */
    public function testSourceCensusOfTimerArms(): void
    {
        $src = file_get_contents(__DIR__ . '/../../src/TraktPlugin.php');
        $this->assertIsString($src);

        $callSites = [];
        foreach (explode("\n", $src) as $line) {
            if (str_contains($line, '\Workerman\Timer::add(')) {
                $callSites[] = trim($line);
            }
        }

        $this->assertCount(2, $callSites, 'expected exactly the one-shot scrobble arm and the periodic pull arm');
        $this->assertContains('\Workerman\Timer::add(0, $task, [], false);', $callSites);
        $this->assertContains('\Workerman\Timer::add($this->syncIntervalSeconds(), function (): void {', $callSites);

        foreach ($callSites as $line) {
            if (preg_match('/Timer::add\(\s*0\s*,/', $line) !== 1) {
                continue; // Not an interval-0 arm (the periodic pull uses a real delay).
            }

            $this->assertStringContainsString(
                '[], false)',
                $line,
                'an interval-0 arm without the trailing `false` is a PERSISTENT '
                . '0ms loop — the scrobble-spammer defect (review trakt__srphp C3)'
            );
        }
    }

    /**
     * Wire an enabled, configured plugin whose media items resolve, with no
     * network I/O (same harness shape as TraktPluginTest).
     *
     * Production order: the host's PluginLoader delivers settings via
     * `configure()` BEFORE `onEnable()` (PluginLoader.php applyPersistedSettings
     * → onEnable), and `schedulePeriodicSync()` reads the master `enabled`
     * switch at arm time — an earlier onEnable-first wiring would arm the pull
     * against a flag the host has not delivered yet.
     */
    private function wiredPlugin(FakeTraktApi $api, bool $masterOn = true): TraktPlugin
    {
        $settings = new TraktSettings(
            accessToken: 'fresh-access',
            refreshToken: 'fresh-refresh',
            expiresAt: time() + 3600,
            username: 'testuser',
        );

        $plugin = new TraktPlugin($settings, new NullLogger(), $api);
        $plugin->configure(array_merge($settings->toArray(), ['enabled' => $masterOn]));

        $container = new StubContainer([
            \Phlix\Media\Library\ItemRepository::class => new FakeItemRepository(),
            \Phlix\Auth\WatchHistory::class => null,
        ]);
        $plugin->onEnable($container);

        return $plugin;
    }

    /**
     * MASTER SWITCH (review trakt__docmaint M1): with the plugin's `enabled`
     * master off at boot, `onEnable()` must NOT arm the recurring
     * Trakt→Phlix pull timer at all. The host boots a plugin from its own
     * enabled column and never consults the plugin's `enabled` SETTING, so
     * without this arm-time gate a "master off" install still kept a live
     * recurring timer inside the resident worker.
     *
     * Mutation-proven: dropping `!$this->enabled` from
     * schedulePeriodicSync()'s guard reddens this test.
     */
    public function testMasterSwitchOffAtBootArmsNoRecurringPullTimer(): void
    {
        $api = new FakeTraktApi();
        $plugin = $this->wiredPlugin($api, masterOn: false);

        $recurring = array_values(array_filter(Timer::$arms, static fn(array $arm): bool => $arm['persistent']));
        $this->assertCount(
            0,
            $recurring,
            'a master-off boot must arm no recurring pull timer (one-shots aside)'
        );
        $this->assertSame([], Timer::$arms, 'with the master off, onEnable must arm nothing');
        $this->assertSame(0, $api->scrobbleStartCalls);
    }
}
