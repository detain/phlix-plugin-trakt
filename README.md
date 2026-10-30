# phlix-plugin-trakt

[![tests](https://github.com/detain/phlix-plugin-trakt/actions/workflows/test.yml/badge.svg)](https://github.com/detain/phlix-plugin-trakt/actions/workflows/test.yml)

> Trakt scrobbler plugin for [Phlix](https://github.com/detain/phlix-server) —
> scrobbles playback to [Trakt.tv](https://trakt.tv) and syncs your watch
> history over the Trakt OAuth API.

## Overview

Connects a Phlix server to your Trakt.tv account:

- **Scrobbles** playback (start / pause / stop) so Trakt shows what you're
  watching in real time and marks items watched at the end.
- **Two-way history sync** — PULLS your Trakt watch history into Phlix on the
  configurable `sync_interval_minutes` interval; PUSHES Phlix completions back
  to Trakt event-driven (on playback stop at ≥ 90%), never on the interval.
  Pushing completions rides on the same toggle as scrobbles: with
  `scrobble_enabled` off, nothing is pushed even while pull sync stays on.
- Authenticates via **OAuth**, refreshing tokens automatically.

It subscribes to `phlix.playback.started`, `phlix.playback.paused`,
`phlix.playback.resumed`, and `phlix.playback.stopped`.

## Install

From the Phlix admin **Plugins** section, paste this repo's URL:

```
https://github.com/detain/phlix-plugin-trakt
```

…or from the CLI:

```bash
php bin/phlix plugin:install https://github.com/detain/phlix-plugin-trakt
```

## Settings

| Setting | Type | Required | Secret | Tier | Default | Description |
|---|---|---|---|---|---|---|
| `enabled` | boolean | No | No | `standard` | `false` | Master on/off for Trakt scrobbling and sync. Optional; default off. |
| `username` | string | No | No | `advanced` | `""` | Display-only: the Trakt account Phlix is linked to (set during authorization). |
| `access_token` | string | No | Yes | `advanced` | `null` | Obtained automatically when you authorize Phlix with Trakt — you do not enter this by hand. Create an [API app](https://app.trakt.tv/settings/apps/api/new) only if you self-host credentials. |
| `refresh_token` | string | No | Yes | `advanced` | `null` | Set automatically during authorization; used to renew the access token. Not entered by hand. |
| `expires_at` | integer | No | Yes | `advanced` | `null` | Unix timestamp when the access token expires. Managed automatically. |
| `sync_enabled` | boolean | No | No | `advanced` | `true` | Sync watched state with Trakt. Optional; default on. |
| `sync_interval_minutes` | integer | No | No | `advanced` | `30` | How often to sync with Trakt. Optional; default 30. |
| `scrobble_enabled` | boolean | No | No | `advanced` | `true` | Report play/pause/stop to Trakt as you watch. Optional; default on. |

> **`access_token` / `refresh_token` / `expires_at` are set automatically via the
> Trakt authorization (OAuth) flow — you never enter them by hand.** Create an API
> application at <https://app.trakt.tv/settings/apps/api/new> only if you self-host your own
> Trakt credentials.

> **Self-host credentials are configured host-side, not in this table.** Your
> app's `client_id` / `client_secret` / `redirect_uri` live on the Phlix
> **server**, which supplies them to the plugin at runtime: set the
> `TRAKT_CLIENT_ID`, `TRAKT_CLIENT_SECRET` and `TRAKT_REDIRECT_URI`
> environment variables, or enter them in the admin Settings page — the
> `trakt.client_id` / `trakt.client_secret` / `trakt.redirect_uri` keys
> there take precedence. The server's `config/scrobblers/trakt.php`
> documents that layer, and its `token_encryption_key` is what the plugin
> uses to encrypt OAuth tokens at rest.

## Rate Limits and Scheduling

Trakt throttles by request rate and answers HTTP 429 with a
`Retry-After` hint. The plugin parses it and surfaces it as
`TraktRateLimitException::$retryAfter`, but **deliberately does not
auto-retry** (review trakt__secaudit #6 / trakt__srphp m11): a scrobble
that hits 429 is logged and dropped, and the next history pull simply
waits for its regular tick — a blind deferred retry against a sustained
rate limiter would queue load instead of relieving it. For the same
reason the `X-Ratelimit-*` headers on successful (2xx) responses are not
carried: nothing in the plugin budgets against them today, so surfacing
them would be theater. `retryAfter` stays on the typed exception as an
informational value for hosts that choose to consume it.

History pulling also has **two independent schedulers**: the plugin's own
periodic timer (armed from `sync_interval_minutes` on enable) and the
host server's scheduled watch-history sweep. Nothing inside this plugin
coordinates the two, so a linked account can see pulls on both cadences;
deduplication is a host-layer responsibility, not something this
repository can promise.

## Development

```bash
composer install
vendor/bin/phpunit                       # default: deterministic Unit suite
vendor/bin/phpunit --testsuite Network   # live httpbin round trips, on demand
vendor/bin/phpstan analyse src --level=9 # CI static-analysis gate
vendor/bin/phpcs src --standard=PSR12    # CI coding-standards gate
php scripts/security-audit-check.php     # audit composer.lock (runtime + dev)
```

These deterministic gates run in CI on PHP 8.3 and 8.4. The phpstan config
(`phpstan.neon`) carries ten broad `ignoreErrors` families — every
"unknown class"/"invalid type" shape, silenced because the host classes they
refer to are not available in plugin scope. The plugin's own typed code is
analysed at level 9 with no per-line suppressions.

The entry class is `Phlix\Plugins\Scrobbler\Trakt\TraktPlugin` (implements
`Phlix\Shared\Plugin\LifecycleInterface` and
`Phlix\Shared\Plugin\ConfigurableInterface`). It runs inside a Phlix server
host, which provides the playback/library services at runtime. Host-supplied
classes (`Phlix\Auth\WatchHistory`, `Phlix\Common\Uuid`,
`Phlix\Media\Library\MediaItem`, `Phlix\Admin\SettingsRepository`,
`Workerman\MySQL\Connection`, `Workerman\Worker`, `Workerman\Http\Client`,
`Workerman\Http\Response`, `Workerman\Timer`) are stubbed from
`tests/bootstrap.php` so the unit suite runs standalone. `phpunit.xml` sets the
default suite to `Unit` and excludes `tests/Network/HttpClientNetworkTest.php`,
which talks to a third-party service.

## License

MIT — see [LICENSE](LICENSE).
