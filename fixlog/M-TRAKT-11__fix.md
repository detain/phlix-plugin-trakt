# M-TRAKT-11 — pull path never refreshes; auth errors lumped with transient failures [srphp M6] — status: LANDED

## Verdict — LANDED (same fix as M-TRAKT-07; one cure closes both queue IDs — secaudit Major-2 and srphp M6 are the same defect from two personas)

The srphp review's own cure prescription — "ensureFreshToken() up front in both
pull entries, and treat an auth failure inside the pull as one refresh then one
re-pull" — shipped verbatim as `pullHistoryWithAuthRecovery()` (see
M-TRAKT-07__fix.md for measured reds, catch-layer law, and suite attribution
267->274/+7, 797->817/+20).
