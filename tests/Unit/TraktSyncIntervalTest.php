<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins\Scrobbler\Trakt;

use Phlix\Plugins\Scrobbler\Trakt\TraktPlugin;
use Phlix\Plugins\Scrobbler\Trakt\TraktSettings;
use PHPUnit\Framework\TestCase;

/**
 * Consequence tests for `sync_interval_minutes`.
 *
 * ## The defect
 *
 * The value was parsed into {@see TraktSettings::$syncIntervalMinutes} and then
 * IGNORED: `schedulePeriodicSync()` armed its timer with the hardcoded
 * `SYNC_INTERVAL_SEC = 1800` constant. Changing the interval in the admin UI did
 * nothing, and the plugin's own help text advertised a setting the code ignored.
 *
 * These assert the resolved interval, not that the setting parses.
 */
final class TraktSyncIntervalTest extends TestCase
{
    private function resolve(int $minutes): int
    {
        $settings = new TraktSettings(
            accessToken: 'a',
            refreshToken: 'r',
            username: 'u',
            syncIntervalMinutes: $minutes,
        );

        $ref = new \ReflectionClass(TraktPlugin::class);
        /** @var TraktPlugin $plugin */
        $plugin = $ref->newInstanceWithoutConstructor();

        $prop = $ref->getProperty('settings');
        $prop->setAccessible(true);
        $prop->setValue($plugin, $settings);

        $m = $ref->getMethod('syncIntervalSeconds');
        $m->setAccessible(true);

        /** @var int $seconds */
        $seconds = $m->invoke($plugin);

        return $seconds;
    }

    /**
     * CONSEQUENCE: the configured interval must determine the timer period.
     *
     * Mutation-verified: restoring `self::SYNC_INTERVAL_SEC` in
     * schedulePeriodicSync() leaves this green, so the companion source
     * assertion below pins the call site.
     */
    public function test_configured_interval_is_honoured(): void
    {
        $this->assertSame(15 * 60, $this->resolve(15));
        $this->assertSame(120 * 60, $this->resolve(120));
    }

    /**
     * A zero or negative interval must not arm a runaway timer in a resident
     * worker — it falls back to the shipped default.
     */
    public function test_non_positive_interval_falls_back_to_the_default(): void
    {
        $this->assertSame(1800, $this->resolve(0));
        $this->assertSame(1800, $this->resolve(-5));
    }

    /**
     * CONSEQUENCE: the timer call site must use the resolved value, not the
     * constant. Without this, the resolver could be correct and unused — which
     * is exactly the shape of the original defect.
     */
    public function test_timer_uses_the_resolved_interval_not_the_constant(): void
    {
        $src = file_get_contents(__DIR__ . '/../../src/TraktPlugin.php');
        $this->assertIsString($src);

        $this->assertStringContainsString(
            'Timer::add($this->syncIntervalSeconds()',
            $src,
            'schedulePeriodicSync() must arm the timer with the configured interval.'
        );
        $this->assertStringNotContainsString(
            'Timer::add(self::SYNC_INTERVAL_SEC',
            $src,
            'The hardcoded constant must not be used as the timer period — that is '
            . 'the defect this fixes. It remains valid only as the fallback inside '
            . 'syncIntervalSeconds().'
        );
    }
}
