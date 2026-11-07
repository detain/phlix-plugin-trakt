<?php

declare(strict_types=1);

namespace Phlix\Plugins\Scrobbler\Trakt;

/**
 * The OAuth state store could not be consulted because its INFRASTRUCTURE
 * failed — distinct from the CSRF-law null of a genuine miss.
 *
 * {@see TraktOAuthStateStore::consume()} documents that a null return MUST
 * be treated by the caller as "state never issued, already consumed, or
 * mismatched" — a SECURITY verdict that declines the callback. That verdict
 * may only be delivered when the store actually ANSWERED. A failed SELECT,
 * a failed one-shot DELETE, or a connection fault is an AVAILABILITY fault:
 * the state's fate is UNKNOWN, and answering it like a replay rejection
 * hid outages behind a security face (review trakt__srphp m14, PG-137 —
 * the parent's `catch (Throwable) → return null` did exactly that, and its
 * own unguarded rollback could then throw a foreign exception out of the
 * handler anyway).
 *
 * Carries no HTTP wire identity: the plugin raises it, the host's callback
 * path decides what the visitor sees.
 *
 * @package Phlix\Plugins\Scrobbler\Trakt
 * @since 1.5.0
 */
final class OAuthStateStoreUnavailableException extends \RuntimeException
{
}
