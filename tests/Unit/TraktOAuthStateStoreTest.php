<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins\Scrobbler\Trakt;

use Phlix\Plugins\Scrobbler\Trakt\SessionTraktOAuthStateStore;
use PHPUnit\Framework\TestCase;

/**
 * Covers the {@see SessionTraktOAuthStateStore} contract: state values
 * survive a put/consume round-trip, mismatches return null, a second
 * consume of the same state is rejected as a replay, and — PG-142 — the
 * entry carries the initiating user's identity so the callback can bind
 * the two instead of accepting any valid state from any caller.
 *
 * See post-O.7 wave 1 security audit, finding H.4.
 */
final class TraktOAuthStateStoreTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        parent::tearDown();
    }

    public function test_round_trip_returns_verifier(): void
    {
        $store = new SessionTraktOAuthStateStore();
        $store->put('state-123', 'verifier-abc');

        self::assertSame('verifier-abc', $store->consume('state-123'));
    }

    public function test_consume_with_mismatched_state_returns_null(): void
    {
        $store = new SessionTraktOAuthStateStore();
        $store->put('state-123', 'verifier-abc');

        self::assertNull($store->consume('state-WRONG'));
    }

    public function test_consume_is_one_shot(): void
    {
        $store = new SessionTraktOAuthStateStore();
        $store->put('state-123', 'verifier-abc');

        self::assertSame('verifier-abc', $store->consume('state-123'));
        // Replay attempt — the entry was wiped on the first consume.
        self::assertNull($store->consume('state-123'));
    }

    public function test_consume_when_never_issued_returns_null(): void
    {
        $store = new SessionTraktOAuthStateStore();

        self::assertNull($store->consume('whatever'));
    }

    public function test_mismatched_state_still_wipes_stored_entry(): void
    {
        $store = new SessionTraktOAuthStateStore();
        $store->put('state-123', 'verifier-abc');

        // A wrong-state attempt MUST also wipe the entry so an attacker
        // cannot probe and then immediately replay with the right state.
        self::assertNull($store->consume('state-WRONG'));
        self::assertNull($store->consume('state-123'));
    }

    // --- PG-142: caller-identity binding ------------------------------------

    public function test_put_binds_caller_identity_and_consume_with_identity_reports_it(): void
    {
        $store = new SessionTraktOAuthStateStore();
        $store->put('state-123', 'verifier-abc', 'user-9');

        $entry = $store->consumeWithIdentity('state-123');

        self::assertIsArray($entry);
        self::assertSame('verifier-abc', $entry['code_verifier']);
        self::assertSame('user-9', $entry['user_id']);
    }

    public function test_consume_is_one_shot_including_the_bound_identity(): void
    {
        $store = new SessionTraktOAuthStateStore();
        $store->put('state-123', 'verifier-abc', 'user-9');

        self::assertSame('verifier-abc', $store->consume('state-123'));
        // consume() must wipe the identity key too: no second read of any
        // shape can resurrect the entry — and no orphan identity may linger
        // in the session behind the consumed state.
        self::assertNull($store->consumeWithIdentity('state-123'));
        self::assertArrayNotHasKey('trakt_oauth_user_id', $_SESSION);
    }

    public function test_empty_string_identity_is_no_identity(): void
    {
        // Parse-at-boundary: '' arrives from form-shaped callers; it must
        // land as "unbound" (null), never as an empty-string identity.
        $store = new SessionTraktOAuthStateStore();
        $store->put('state-123', 'verifier-abc', '');

        $entry = $store->consumeWithIdentity('state-123');

        self::assertIsArray($entry);
        self::assertNull($entry['user_id']);
    }

    public function test_unbound_entry_reports_null_identity(): void
    {
        $store = new SessionTraktOAuthStateStore();
        $store->put('state-123', 'verifier-abc');

        $entry = $store->consumeWithIdentity('state-123');

        self::assertIsArray($entry);
        self::assertSame('verifier-abc', $entry['code_verifier']);
        self::assertNull($entry['user_id']);
    }

    public function test_mismatched_state_via_identity_read_still_wipes_entry(): void
    {
        $store = new SessionTraktOAuthStateStore();
        $store->put('state-123', 'verifier-abc', 'user-9');

        self::assertNull($store->consumeWithIdentity('state-WRONG'));
        // The wipe is unconditional: the legitimate follow-up gets nothing.
        self::assertNull($store->consume('state-123'));
    }
}
