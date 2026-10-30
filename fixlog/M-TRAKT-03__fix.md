# M-TRAKT-03 — README sync bullet wrong about push cadence, hides scrobble-toggle coupling [docmaint M3] — status: LANDED

## Verdict — LANDED (dce8ec1)

VERIFY-FIRST at tip 0026505: LIVE (reconcile's "partial cure by e37e63e, verify"
checked: e37e63e fixed the master-switch enforcement, not this bullet).
README:15-16 still read "pulls ... and pushes Phlix completions back to Trakt on
a configurable interval" — false for the push direction (push is event-driven on
PlaybackStopped >= 90%, never interval-cadenced) and it hid the coupling: with
`scrobble_enabled` off, `onPlaybackStopped()` early-returns before the
reachedEnd/push block, so completions are never pushed even though the README
sold push as a sync_enabled/interval concern (disk-verified the early-return at
tip before writing the fix).

Cure: bullet rewritten to the implemented truth — PULL on the configurable
`sync_interval_minutes` interval; PUSH event-driven at >= 90%, riding the
`scrobble_enabled` toggle, explicitly disclosed. Docs-only; no behavior moved
(behavior itself is owner-ratified; the defect was the prose).
