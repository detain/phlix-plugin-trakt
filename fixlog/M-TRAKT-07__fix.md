# M-TRAKT-07 — pull-sync runs with never a refresh; 401 swallowed as generic warning [secaudit Major-2] — status: LANDED

## Verdict — LANDED (this branch's fix commit; same cure as M-TRAKT-11 — srphp M6 is the same defect)

VERIFY-FIRST at tip 729c722: LIVE. Neither `syncHistoryFromTrakt()` (public entry)
nor `runScheduledSync()` (timer tick) ever consulted `ensureFreshToken()`; the tick
dialled `/users/{id}/history` with whatever token the DB held, and
`syncTraktToPhlix()`'s blanket `catch (TraktApiException)` (parent of
TraktAuthenticationException) downgraded every 401 to one generic warning line —
permanent silent sync death with scrobble_enabled=false (no scrobble ever rotating
the token) until manual re-auth.

Cure: new private `pullHistoryWithAuthRecovery()` is THE single pull body for both
entries (same cannot-drift law as `makeHistorySync()`): proactive gated rotation
first (skip tick on unrecoverable expiry — loud warning naming re-auth), and the
sync layer now RETHROWS TraktAuthenticationException (transient API failures keep
the shipped warning-and-zero law, pinned), letting the entry run exactly one gated
`refreshAfterAuthFailure()` + exactly one re-pull. No retry loop. Public contract
of `syncHistoryFromTrakt` unchanged (int, never throws auth at the host).

Tests: 5 pull-lifecycle tests (tick proactive-refresh, tick skip-on-failed-refresh,
on-demand proactive-refresh, 401->one-refresh->one-repull, failed-recovery gives up
cleanly) + 2 sync-layer contract tests (auth rethrow; transient swallow + no local
writes). 6 of 7 measured RED before the src cure (errors = auth escaping the old
entry; failures = 0-vs-1 refresh counts); transient-swallow green by design (pin of
unchanged law). Suite 267 -> 274 (+7), assertions 797 -> 817 (+20). RecordingHistorySync
fake gained pullCalls/throwAuthOnce. phpstan src L9 + full-corpus clean; phpcs src
PSR12 clean. S440 pair zero-diff.
