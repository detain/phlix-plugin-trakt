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

| Setting | Type | Required | Secret | Default | Description |
|---|---|---|---|---|---|
| `enabled` | boolean | No | No | `false` | Master on/off for Trakt scrobbling and sync. Optional; default off. |
| `username` | string | No | No | `""` | Display-only: the Trakt account Phlix is linked to (set during authorization). |
| `access_token` | string | No | Yes | `null` | Obtained automatically when you authorize Phlix with Trakt — you do not enter this by hand. Create an [API app](https://app.trakt.tv/settings/apps/api/new) only if you self-host credentials. |
| `refresh_token` | string | No | Yes | `null` | Set automatically during authorization; used to renew the access token. Not entered by hand. |
| `expires_at` | integer | No | Yes | `null` | Unix timestamp when the access token expires. Managed automatically. |
| `sync_enabled` | boolean | No | No | `true` | Sync watched state with Trakt. Optional; default on. |
| `sync_interval_minutes` | integer | No | No | `30` | How often to sync with Trakt. Optional; default 30. |
| `scrobble_enabled` | boolean | No | No | `true` | Report play/pause/stop to Trakt as you watch. Optional; default on. |

> **`access_token` / `refresh_token` / `expires_at` are set automatically via the
> Trakt authorization (OAuth) flow — you never enter them by hand.** Create an API
> application at <https://app.trakt.tv/settings/apps/api/new> only if you self-host your own
> Trakt credentials.

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
