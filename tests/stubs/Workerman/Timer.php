<?php

declare(strict_types=1);

namespace Workerman;

/**
 * Minimal stub for the host-supplied Workerman Timer.
 *
 * The real class lives in the phlix-server runtime (`workerman/workerman`) and
 * is not part of this plugin's dependency closure, so before this stub existed
 * every unit test silently took the plugin's synchronous fallback branch and the
 * timer-registration arguments were never exercised at all — which is how
 * `Timer::add(0, $cb)` (review trakt__srphp C3: a PERSISTENT 0ms loop, i.e. an
 * immortal scrobble spammer) stayed green while production burned the worker.
 *
 * The stub models exactly the semantics the fix must be pinned against:
 *
 *  - the real signature INCLUDING `bool $persistent = true`, so an arm that
 *    forgets the 4th argument is recorded as persistent — the defect shape;
 *  - a non-persistent arm (`delay()`, i.e. one-shot) fires immediately, the
 *    unit-test equivalent of "next event-loop tick";
 *  - a persistent arm (`repeat()`) is recorded but NEVER fired, so the periodic
 *    sync timer can be inspected from onEnable() without driving a network call.
 *
 * @internal test double
 */
class Timer
{
    /**
     * Every arm made since the last reset().
     *
     * @var list<array{interval: float, persistent: bool}>
     */
    public static array $arms = [];

    /**
     * Add a timer — mirrors `Workerman\Timer::add()` (upstream src/Timer.php).
     *
     * @param float $timeInterval Seconds between/after firing; 0 means "next tick"
     * @param callable $func Callback to invoke
     * @param array<mixed>|null $args Arguments forwarded to the callback
     * @param bool $persistent True repeats, false fires once
     *
     * @return int Timer id
     */
    public static function add(float $timeInterval, callable $func, ?array $args = [], bool $persistent = true): int
    {
        self::$arms[] = ['interval' => $timeInterval, 'persistent' => $persistent];

        if (!$persistent) {
            $func(...($args ?? []));
        }

        return count(self::$arms);
    }

    /**
     * Discard recorded arms (call from test setUp).
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$arms = [];
    }
}
