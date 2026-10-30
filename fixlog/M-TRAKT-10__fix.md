# M-TRAKT-10 — pull consumes /users/{id}/watched with a /history response shape [srphp M5] — status: LANDED

## Verdict — LANDED (this branch's fix commit)

VERIFY-FIRST at tip b9dd29e: LIVE. `TraktApi::getWatchedHistory()` still issued
`GET /users/{urlencode(username)}/watched` (:358 era) while every parser in
`TraktHistorySync` (findMediaItemId / parseWatchedAt / extractDurationTicks) reads
flat HISTORY entries. The queue's `cured-by: C06 934aa69` claim was checked: 934aa69
is the RATING-pull fix (persists rating, stops watch_history wipe) — it touches no
history endpoint; reconcile's TRUE-MISS verdict upheld.

Wire truth (not invented): endpoint + entry shapes re-anchored from the official
Trakt API blueprint fetched 2026-10-30 (docs.trakt.apiary `/users/{id}/history`
samples: `{id, watched_at, action, type, movie{title,year,ids}}` /
`{episode{season,number,title,ids}, show{...}}`; OAuth, X-Pagination page/limit).

Cure: endpoint switched to `/users/{id}/history` (the review's primary option —
existing parsers match it, no re-plumbing of TraktHistorySync); the
bug-encoding pin in `TraktApiTest::testGetWatchedHistoryUsesCorrectEndpoint`
(assertStringContainsString '/watched') flipped to the new law with a
`/watched`-absence negative pin; new `testFindMediaItemIdResolvesRealHistoryEntryShapes`
drives verbatim doc samples through findMediaItemId (movie + episode resolve,
show-level skipped).

DISCLOSED (deliberately out of this lane): history entries carry no `runtime`,
so real entries land in the C2 preserve-local guard (no-runtime → skip write,
934aa69-era law, mutation-tested). Resolving local duration from item metadata
would be a behavior change for a follow-up; reported to the orchestrator.
Suite 266 -> 267 (+1; endpoint pin flipped in place). phpstan src L9 + phpcs src
PSR12 clean. S440 pair zero-diff.
