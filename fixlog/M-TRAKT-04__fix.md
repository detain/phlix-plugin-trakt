# M-TRAKT-04 — pull conflict policy "last-write-wins based on watchedAt timestamp" not implemented [docmaint M4] — status: LANDED

## Verdict — LANDED (00778b1)

VERIFY-FIRST at tip 0026505: LIVE. TraktHistorySync :51 asserted "Uses
last-write-wins based on watchedAt timestamp"; $watchedAt is parsed
(parseWatchedAt) and consumed ONLY by the debug-log line — zero timestamp
comparison anywhere in syncTraktToPhlix. The class-header :15-16 half-truth
("writes new entries") was the review's companion finding and was corrected in
the same commit.

Cure (review's option 1 — narrate the implemented policy; shipping a real
watchedAt merge would be an unratified behavior change): the docblock now states
the actual law — local >= 90% always wins (skip); a sub-threshold local row is
overwritten unconditionally by a completed Trakt entry regardless of either
side's timestamp; watchedAt is log context only; a true last-write-wins merge is
explicitly named as NOT implemented. Docs-only; suite 264/787/3 held unchanged.
