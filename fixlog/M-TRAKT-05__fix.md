# M-TRAKT-05 — composer constraint disjoint from host reality + release prose lags tags [docmaint M2] — status: LANDED (constraint half) + RECORDED (tag half, owner-gated)

## Verdict — LANDED (this branch's fix commit)

VERIFY-FIRST at tip 07c89ce: LIVE. `composer.json` declared
`"detain/phlix-shared": "^0.21.0"` (own lock: 0.21.0 @1299465) while the hosts
moved: phlix-server pins `^0.51.0` (lock v0.51.0) and phlix-hub / sibling anidb
are on `^0.52.0` (lock v0.52.0) — caret semantics make 0.21.x and those lines
disjoint, exactly the queue's "cannot co-resolve" claim.

Cure (review's option 1 — bump to the current shared line, anidb precedent
^0.52.0): constraint `^0.52.0`; `composer update detain/phlix-shared` — lock
delta SURGICAL (node version/reference/dist/source + content-hash only:
0.21.0@1299465 -> v0.52.0@febd8af577d70644fd2686084010b514b002c1ed, tag->peel
pair ls-remote-verified this lane; +v0.52.0's own `cs-tests` script metadata).
Compatibility proven from the inside, not assumed: every shared symbol this
plugin touches (Plugin/LifecycleInterface, Plugin/ConfigurableInterface,
Events/**) is BYTE-IDENTICAL between the old vendor copy and v0.52.0 (diff -rq
this lane); full gates on the new line: suite 274/817/4 unchanged, phpstan src
L9 clean, `composer validate --strict` OK, S440 `security-audit-check.php`
executed green on the new lock (33 pkgs, zero advisories), S440 pair files
zero-diff.

RECORDED (second half — not lane-actionable): "release docs lag the tags" —
tags stop at v1.2.0 while plugin.json carries 1.5.0 (v1.3.0-v1.5.0 never
tagged); the repo family ships NO CHANGELOG.md (established fact from the
plugin review rounds), and estate law in this campaign is no-tags-from-fix-
lanes. Rolling release notes is the owner's tag-wave call; nothing here is
false prose (README carries no version claims).
