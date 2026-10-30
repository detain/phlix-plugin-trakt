# M-TRAKT-06 — 401-recovery refresh bypasses the single-flight rotation gate [secaudit Major-1] — status: LANDED

## Verdict — LANDED (this branch's fix commit; same cure as M-TRAKT-09, see that file for the full note)

VERIFY-FIRST at tip 0026505 (era d477b85/015d0ba guards + post-corpus fixes 95026cc..61831e5):
LIVE. `refreshAfterAuthFailure()` called `$this->api->refreshAccessToken($refreshToken)`
directly with no `beginRefresh()`/`endRefresh()`/await — while its own docblock asserted
"The single-flight lock (B4) will wrap the same refresh path." Measured red before the fix:
re-entrant 401-recovery test saw TWO `/oauth/token` exchanges of one rotating refresh token
("Failed asserting that 2 is identical to 1").

Cure: the reactive path now runs the same gate protocol as `ensureFreshToken()` — await the
in-flight owner, reuse on observed rotation (refresh-token value swapped = proof another
exchange completed), refuse on synchronous re-entry, and double-check under the gate.
`$api` is a local binding (phpstan L9 property-narrowing law + honest against re-assignment).
Docblock rewritten to narrate the shipped protocol.

Tests: `testConcurrent401RecoveryTriggersExactlyOneExchange` (re-entrant fake drives a second
stop event mid-exchange; asserts exactly one exchange + no re-entrant POST) and
`testCoroutineConcurrent401RecoveriesExchangeOnce` (two real coroutines, ext-swoole-gated —
same env-shape skip class as the existing `testCoroutineSingleFlightExchangesOnce`).
Suite 264/787 -> 266/793 (+2 tests measured at red, green after). phpstan src L9 clean,
phpcs src PSR12 clean. S440 pair zero-diff.
