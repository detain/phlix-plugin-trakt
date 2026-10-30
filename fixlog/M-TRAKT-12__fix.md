# M-TRAKT-12 — tick scale asserted twice, inconsistently; fixture fabricates 100-ns TICKS_PER_SECOND [srphp M7] — status: LANDED

## Verdict — LANDED (this branch's fix commit)

VERIFY-FIRST at tip cb95c82: LIVE. `tests/bootstrap.php` stub carried
`TICKS_PER_SECOND = 10_000_000` (the host's PROSE scale) while the host's
ARITHMETIC constant is `10000` (phlix-server src/Auth/WatchHistory.php:128 —
disk-verified this lane); `computeProgressPercent()` divided by a hardcoded
`10_000_000` literal instead of the constant, so the repo asserted both scales
and passed only while both sides agreed on the fabricated one.

Cure (single-source the scale, review's prescription):
- stub `TICKS_PER_SECOND` -> `10000`, mirroring the host's numeric constant,
  with the host-internal prose/math contradiction documented in the comment;
- `computeProgressPercent()` now divides by `WatchHistory::TICKS_PER_SECOND`
  (the repo's third tick site, `extractDurationTicks`, already read the constant);
- all 12 test literals converted to `SECONDS * WatchHistory::TICKS_PER_SECOND`
  form (percent tests re-pinned to their documented seconds intent; the
  scale-neutral payload sites keep byte-ident values in honest form), so the
  suite tracks ONE scale — the host's.

Measured reds before re-pin (the exact double-assertion class): 4 tests
(3x extractDurationTicks pins + testScrobbleReportsProgressAsPercentageNotSeconds)
failed when the stub moved to the host scale while expectations/percent still
baked 1e7; green after the constant re-pin. Suite totals held 274/817/4 (in-place
re-pin, no new tests). phpstan (src L9 + full corpus) + phpcs src clean.

UPSTREAM TICKET (for the orchestrator, not fixable here): the host's own file
contradicts itself — :34-36 docblock says "100-nanosecond intervals"
(MB/EBML ticks) while the arithmetic constant + /10000 math say 10,000 ticks/s
(0.1 ms). The plugin now follows the host's NUMBERS; phlix-server should reconcile
its prose or its constant.
