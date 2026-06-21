# phlix-plugin-trakt

[![tests](https://github.com/detain/phlix-plugin-trakt/actions/workflows/test.yml/badge.svg)](https://github.com/detain/phlix-plugin-trakt/actions/workflows/test.yml)

> Trakt scrobbler plugin for [Phlix](https://github.com/detain/phlix-server) —
> scrobbles playback to [Trakt.tv](https://trakt.tv) and syncs your watch
> history over the Trakt OAuth API.

## Overview

Connects a Phlix server to your Trakt.tv account:

- **Scrobbles** playback (start / pause / stop) so Trakt shows what you're
  watching in real time and marks items watched at the end.
- **Two-way history sync** — pulls your Trakt watch history into Phlix and
  pushes Phlix completions back to Trakt on a configurable interval.
- Authenticates via **OAuth**, refreshing tokens automatically.

It subscribes to `phlix.playback.started`, `phlix.playback.stopped`, and
`phlix.playback.progress`.

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

| Setting | Type | Description |
|---|---|---|
| `enabled` | bool | Enable Trakt scrobbling. |
| `username` | string | Your Trakt username. |
| `access_token` / `refresh_token` / `expires_at` | — | OAuth tokens (managed automatically). |
| `sync_enabled` | bool | Enable two-way watch-history sync. |
| `sync_interval_minutes` | int | How often to sync Trakt history into Phlix (minutes). |
| `scrobble_enabled` | bool | Scrobble on playback events. |

## Development

```bash
composer install
vendor/bin/phpunit
```

The entry class is `Phlix\Plugins\Scrobbler\Trakt\TraktPlugin` (implements
`Phlix\Shared\Plugin\LifecycleInterface`). It runs inside a Phlix server host,
which provides the playback/library services at runtime.

## License

MIT — see [LICENSE](LICENSE).
