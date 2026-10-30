# M-TRAKT-09 — refreshAfterAuthFailure() bypasses the single-flight refresh gate [srphp M4] — status: LANDED

## Verdict — LANDED (same fix as M-TRAKT-06; one cure closes both queue IDs — secaudit Major-1 and srphp M4 are the same defect from two personas)

Evidence coords from the review (head 8afbc46) re-verified live at tip 0026505: the method
performed the exchange with no gate, while the class header (:36-40) claimed two concurrent
playback events "can never both POST the same refresh token". The srphp review's requested
cure — route the recovery through the gate + a two-coroutine test asserting exactly one
`/oauth/token` POST — is shipped as described: gate protocol with rotation-observed reuse,
plus the re-entrant (non-swoole) and true two-coroutine tests. See M-TRAKT-06__fix.md for
the measured red, gate semantics, and gate-attribution figures (266/793/4, +2 exact).
