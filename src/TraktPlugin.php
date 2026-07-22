<?php

declare(strict_types=1);

namespace Phlix\Plugins\Scrobbler\Trakt;

use Phlix\Auth\WatchHistory;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Library\MediaItem;
use Phlix\Shared\Plugin\ConfigurableInterface;
use Phlix\Shared\Plugin\LifecycleInterface;
use Phlix\Shared\Events\Playback\PlaybackPaused;
use Phlix\Shared\Events\Playback\PlaybackResumed;
use Phlix\Shared\Events\Playback\PlaybackStarted;
use Phlix\Shared\Events\Playback\PlaybackStopped;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Workerman\MySQL\Connection;

/**
 * Trakt.tv scrobbler plugin entry class.
 *
 * Subscribes to PlaybackStarted, PlaybackPaused, PlaybackResumed, and
 * PlaybackStopped events and submits scrobble data to Trakt.tv using the
 * 3-state scrobble protocol (start/pause/stop; resume re-sends "start").
 *
 * Also handles two-way watch history sync:
 * - Phlix → Trakt: On PlaybackStopped with ≥ 90% completion
 * - Trakt → Phlix: On scheduled sync (via TraktHistorySync)
 *
 * OAuth token lifecycle: before every scrobble the plugin calls
 * {@see TraktPlugin::ensureFreshToken()}, which refreshes an expired access
 * token, rotates the stored access/refresh tokens, recomputes `expires_at`,
 * and persists the new tokens through the optional {@see TraktSettingsRepository}.
 * A 401 raised mid-scrobble triggers a single refresh-then-retry. Because Trakt
 * rotates the refresh token on every exchange, the refresh is guarded by a
 * per-account single-flight gate so two concurrent playback events can never
 * both POST the same refresh token (which would invalidate the account); the
 * second caller awaits the first and reuses the freshly-rotated token.
 *
 * Token security (step S1): the OAuth access/refresh tokens are encrypted at
 * rest via an injected {@see TokenCipher} (libsodium by default) before they
 * are written through the {@see TraktSettingsRepository}, decrypted on load,
 * and NEVER returned to the admin SPA — {@see TraktPlugin::getSettingsForSpa()}
 * exposes only a redacted projection ({@see TraktSettings::toSpaArray()}). When
 * no cipher/key is configured the plugin degrades gracefully (logs a warning
 * and stores the tokens as-is) rather than failing.
 *
 * @package Phlix\Plugins\Scrobbler\Trakt
 * @since 0.14.0
 */
final class TraktPlugin implements LifecycleInterface, ConfigurableInterface
{
    /**
     * Plugin type identifier used in the plugin manifest.
     */
    public const PLUGIN_TYPE = 'scrobbler';

    /**
     * Interval for periodic Trakt→Phlix history sync (30 minutes).
     */
    private const SYNC_INTERVAL_SEC = 1800;

    private ?ItemRepository $itemRepository = null;
    private ?WatchHistory $watchHistory = null;
    private ?LoggerInterface $logger = null;
    private ?TraktApi $api = null;
    private ?Connection $db = null;

    /**
     * Writer used to persist rotated tokens. Optional: the host may not
     * provide one, in which case persistence is a no-op (with a warning).
     */
    private ?TraktSettingsRepository $settingsRepository = null;

    /**
     * Cipher used to encrypt OAuth tokens at rest (step S1). Optional: the host
     * may not provide a key/cipher, in which case tokens are stored as-is and a
     * warning is logged on persist. Resolved from the container in
     * {@see TraktPlugin::onEnable()} (same optional-service pattern as the
     * settings repository) but may also be injected directly (test seam).
     */
    private ?TokenCipher $tokenCipher = null;

    /**
     * Single-flight gate around the OAuth refresh (step B4).
     *
     * Trakt ROTATES the refresh token on every `/oauth/token` exchange, so two
     * concurrent playback events that both observe an expired token and both
     * POST the SAME refresh token would invalidate the account (the second
     * exchange is rejected and logs the user out). This guard ensures only ONE
     * refresh is ever in flight per plugin instance (= per account): the first
     * caller performs the refresh while concurrent callers AWAIT its result and
     * then re-check {@see TraktSettings::isTokenExpired()} (double-checked
     * locking), using the freshly-rotated token instead of refreshing again.
     *
     * Implemented coroutine-safely: when running inside a Swoole coroutine the
     * await is a {@see \Swoole\Coroutine\Channel} pop (it YIELDS — never a
     * blocking sleep or busy-wait); outside a coroutine (e.g. unit tests, CLI)
     * the same flag serialises the in-process call path. The guard is keyed by
     * this instance because each plugin instance is scoped to one account's
     * settings.
     */
    private bool $refreshInProgress = false;

    /**
     * Channel used as the coroutine await primitive for the single-flight gate.
     * Lazily created only when actually running inside a coroutine; it carries
     * no payload (signalling only) and is recreated per refresh cycle so a
     * failed refresh never wedges the next one.
     *
     * @var \Swoole\Coroutine\Channel|null
     */
    private $refreshLock = null;

    /** Disables all scrobbling and sync when false. */
    private bool $enabled = false;

    /** User-specific settings (tokens, username, prefs). */
    private TraktSettings $settings;

    /**
     * @param TraktSettings|null $settings Initial settings (loaded from DB on enable)
     * @param LoggerInterface|null $logger Optional PSR-3 logger
     * @param TraktApi|null $api Pre-built API client (test seam; production builds it in onEnable())
     * @param TraktSettingsRepository|null $settingsRepository Persistence writer for rotated tokens
     *                                     (test seam; production resolves it from the container)
     * @param TokenCipher|null $tokenCipher Cipher used to encrypt tokens at rest
     *                                     (test seam; production resolves it from the container)
     */
    public function __construct(
        ?TraktSettings $settings = null,
        ?LoggerInterface $logger = null,
        ?TraktApi $api = null,
        ?TraktSettingsRepository $settingsRepository = null,
        ?TokenCipher $tokenCipher = null,
    ) {
        $this->settings = $settings ?? new TraktSettings();
        $this->logger = $logger ?? new NullLogger();
        $this->api = $api;
        $this->settingsRepository = $settingsRepository;
        $this->tokenCipher = $tokenCipher;
    }

    /**
     * Configure the plugin from a settings array (persisted in the DB
     * by the plugin loader and passed back on enable).
     *
     * @param array<string, mixed> $settings Key-value settings from plugins.settings_json
     *
     * @return void
     *
     * @since 0.14.0
     */
    public function configure(array $settings): void
    {
        // Decrypt tokens on the way in when a cipher is available (they are
        // written encrypted by persistSettings()). When no cipher is present
        // fromArray() takes the token fields verbatim, and the cipher itself
        // passes through any value it does not recognise as its own ciphertext
        // (legacy plaintext), so upgrades load correctly either way.
        $this->settings = TraktSettings::fromArray($settings, $this->tokenCipher);
        $this->enabled = ($settings['enabled'] ?? false) === true;
    }

    /**
     * Boot-safe "WIRE" step — MUST NOT perform blocking I/O.
     *
     * This is the safe half of the deliberate wire/connect split (plan_plugins
     * §2 F1(b)): onEnable only resolves host services from the container and
     * constructs cheap objects (the API client, the token cipher, the periodic
     * sync timer). It performs NO network calls, NO OAuth token exchange and NO
     * DB queries — the container resolves are object lookups, and the timer is
     * merely armed, not run. All actual network/token work ("CONNECT") is
     * deferred to first use inside the event handlers via
     * {@see TraktPlugin::ensureFreshToken()} (lazy refresh on the first scrobble)
     * and the timer tick. This is why the Trakt plugin is safe to activate at
     * worker boot where blocking-onEnable plugins (anidb/omdb/lastfm) are not.
     *
     * @param ContainerInterface $container Host PSR-11 container
     *
     * @return void
     *
     * @since 0.14.0
     */
    public function onEnable(ContainerInterface $container): void
    {
        // --- WIRE: resolve host services (object lookups; no network/DB I/O) ---
        if ($this->logger instanceof NullLogger) {
            $logger = $container->get(LoggerInterface::class);
            $this->logger = $logger instanceof LoggerInterface ? $logger : new NullLogger();
        }

        $itemRepo = $container->get(ItemRepository::class);
        $this->itemRepository = $itemRepo instanceof ItemRepository ? $itemRepo : null;

        $watchHist = $container->get(WatchHistory::class);
        $this->watchHistory = $watchHist instanceof WatchHistory ? $watchHist : null;

        try {
            $db = $container->get(Connection::class);
            $this->db = $db instanceof Connection ? $db : null;
        } catch (\Throwable $e) {
            $this->logger?->warning('Trakt: database connection unavailable; Trakt→Phlix sync will not function', [
                'error' => $e->getMessage(),
            ]);
            $this->db = null;
        }

        $this->resolveSettingsRepository($container);
        $this->resolveTokenCipher($container);

        // WIRE: construct the API client only (no auth exchange, no request).
        $this->initApi();

        // WIRE: arm the periodic Trakt→Phlix history sync timer. This only
        // schedules the tick; the CONNECT work (token refresh + HTTP pull) runs
        // later, inside runScheduledSync(), never here at boot.
        $this->schedulePeriodicSync($container);
    }

    /**
     * Resolve the optional settings-persistence writer from the container.
     *
     * Mirrors the plugin's existing optional-service pattern: the host is not
     * required to bind a {@see TraktSettingsRepository}. When it is missing we
     * leave the writer null and rotated tokens persist as a no-op (with a
     * warning logged at persist time) rather than hard-failing.
     *
     * @param ContainerInterface $container Host PSR-11 container
     *
     * @return void
     */
    private function resolveSettingsRepository(ContainerInterface $container): void
    {
        if ($this->settingsRepository !== null) {
            return;
        }

        try {
            if ($container->has(TraktSettingsRepository::class)) {
                $repo = $container->get(TraktSettingsRepository::class);
                $this->settingsRepository = $repo instanceof TraktSettingsRepository ? $repo : null;
            }
        } catch (\Throwable $e) {
            $this->logger?->warning('Trakt: settings repository unavailable; rotated tokens will not persist', [
                'error' => $e->getMessage(),
            ]);
            $this->settingsRepository = null;
        }
    }

    /**
     * Resolve the optional token cipher used to encrypt OAuth tokens at rest.
     *
     * Mirrors the optional-service pattern used for the settings repository:
     * the host is not required to bind a {@see TokenCipher}. Resolution order:
     *  1. A {@see TokenCipher} bound in the container (host override), else
     *  2. a {@see SodiumTokenCipher} built from the `token_encryption_key`
     *     entry of the plugin config.
     * When neither yields a cipher we leave it null and
     * {@see TraktPlugin::persistSettings()} stores tokens as-is (with a
     * warning) rather than hard-failing.
     *
     * @param ContainerInterface $container Host PSR-11 container
     *
     * @return void
     */
    private function resolveTokenCipher(ContainerInterface $container): void
    {
        if ($this->tokenCipher !== null) {
            return;
        }

        try {
            if ($container->has(TokenCipher::class)) {
                $cipher = $container->get(TokenCipher::class);
                if ($cipher instanceof TokenCipher) {
                    $this->tokenCipher = $cipher;
                    return;
                }
            }
        } catch (\Throwable $e) {
            $this->logger?->warning('Trakt: token cipher unavailable from container; tokens may be stored unencrypted', [
                'error' => $e->getMessage(),
            ]);
        }

        // Fall back to a libsodium cipher built from the host config key.
        $config = $this->loadConfig();
        $this->tokenCipher = SodiumTokenCipher::fromConfig($config['token_encryption_key'] ?? null);

        if ($this->tokenCipher === null) {
            $this->logger?->warning('Trakt: no token encryption key configured; OAuth tokens will be stored unencrypted');
        }
    }

    /**
     * Release resources on disable.
     *
     * @return void
     *
     * @since 0.14.0
     */
    public function onDisable(): void
    {
        $this->itemRepository = null;
        $this->watchHistory = null;
    }

    /**
     * Return the event subscriptions for this plugin.
     *
     * @return array<class-string, string|callable>
     *
     * @since 0.14.0
     */
    public function subscribedEvents(): array
    {
        return [
            PlaybackStarted::class => 'onPlaybackStarted',
            PlaybackStopped::class => 'onPlaybackStopped',
            PlaybackPaused::class => 'onPlaybackPaused',
            PlaybackResumed::class => 'onPlaybackResumed',
        ];
    }

    /**
     * Handle playback start — submit scrobble start to Trakt.
     *
     * @param PlaybackStarted $event The playback started event
     *
     * @return void
     *
     * @since 0.14.0
     */
    public function onPlaybackStarted(PlaybackStarted $event): void
    {
        if (!$this->isConfigured()) {
            return;
        }

        if (!$this->settings->scrobbleEnabled) {
            $this->logger?->debug('Trakt: scrobble disabled, skipping start');
            return;
        }

        $mediaItem = $this->findMediaItem($event->mediaItemId);
        if ($mediaItem === null) {
            $this->logger?->debug('Trakt: media item not found', [
                'media_item_id' => $event->mediaItemId,
            ]);
            return;
        }

        $progressSecs = (int)($event->positionTicks / 10_000_000);

        // B5: Ensure token is fresh BEFORE scheduling async call.
        // Token refresh is synchronous but fast (single HTTP round-trip to Trakt).
        // The actual scrobble HTTP call is deferred to avoid blocking the worker.
        $this->ensureFreshToken();
        $accessToken = $this->settings->accessToken ?? '';

        /** @var TraktApi $api */
        $api = $this->api;

        // B5: Non-blocking async scrobble via Workerman\Timer::add(0, ...).
        // Schedule on next event-loop tick so the worker returns to its loop
        // immediately. Falls back to synchronous execution when Workerman\Timer
        // is unavailable (e.g. CLI, unit tests outside a Workerman process).
        $this->scheduleAsyncScrobbleStart($mediaItem, $progressSecs, $accessToken, $api);
    }

    /**
     * Handle playback stop — submit scrobble stop to Trakt.
     *
     * Also triggers Phlix → Trakt sync if the item reached ≥ 90% completion.
     *
     * @param PlaybackStopped $event The playback stopped event
     *
     * @return void
     *
     * @since 0.14.0
     */
    public function onPlaybackStopped(PlaybackStopped $event): void
    {
        if (!$this->isConfigured()) {
            return;
        }

        if (!$this->settings->scrobbleEnabled) {
            return;
        }

        $mediaItem = $this->findMediaItem($event->mediaItemId);
        if ($mediaItem === null) {
            $this->logger?->debug('Trakt: media item not found on stop', [
                'media_item_id' => $event->mediaItemId,
            ]);
            return;
        }

        $progressSecs = (int)($event->finalPositionTicks / 10_000_000);

        // B5: Ensure token is fresh BEFORE scheduling async call.
        $this->ensureFreshToken();
        $accessToken = $this->settings->accessToken ?? '';

        /** @var TraktApi $api */
        $api = $this->api;

        // B5: Non-blocking async scrobble via Workerman\Timer::add(0, ...).
        $this->scheduleAsyncScrobbleStop($mediaItem, $progressSecs, $accessToken, $api);

        // syncToTrakt is synchronous (DB writes + potential API call) but only
        // runs when reachedEnd=true, which is already a terminal event for
        // this playback session, so blocking is acceptable there.
        if ($event->reachedEnd && $this->watchHistory !== null) {
            $this->syncToTrakt($event->mediaItemId, $event->finalPositionTicks);
        }
    }

    /**
     * Handle playback pause — submit scrobble pause to Trakt.
     *
     * B6: Trakt's 3-state scrobble model supports pause; this mirrors the
     * start handler but calls scrobblePause() instead.
     *
     * @param PlaybackPaused $event The playback paused event
     *
     * @return void
     *
     * @since 0.14.0
     */
    public function onPlaybackPaused(PlaybackPaused $event): void
    {
        if (!$this->isConfigured() || !$this->settings->scrobbleEnabled) {
            return;
        }

        $mediaItem = $this->findMediaItem($event->mediaItemId);
        if ($mediaItem === null) {
            $this->logger?->debug('Trakt: media item not found on pause', [
                'media_item_id' => $event->mediaItemId,
            ]);
            return;
        }

        $progressSecs = (int)($event->positionTicks / 10_000_000);

        $this->ensureFreshToken();
        $accessToken = $this->settings->accessToken ?? '';

        /** @var TraktApi $api */
        $api = $this->api;

        $this->scheduleAsyncScrobblePause($mediaItem, $progressSecs, $accessToken, $api);
    }

    /**
     * Handle playback resume — submit scrobble start to Trakt.
     *
     * B6: Trakt's 3-state scrobble model uses "start" to resume after a pause.
     * This mirrors the start handler exactly.
     *
     * @param PlaybackResumed $event The playback resumed event
     *
     * @return void
     *
     * @since 0.14.0
     */
    public function onPlaybackResumed(PlaybackResumed $event): void
    {
        if (!$this->isConfigured() || !$this->settings->scrobbleEnabled) {
            return;
        }

        $mediaItem = $this->findMediaItem($event->mediaItemId);
        if ($mediaItem === null) {
            $this->logger?->debug('Trakt: media item not found on resume', [
                'media_item_id' => $event->mediaItemId,
            ]);
            return;
        }

        $progressSecs = (int)($event->positionTicks / 10_000_000);

        $this->ensureFreshToken();
        $accessToken = $this->settings->accessToken ?? '';

        /** @var TraktApi $api */
        $api = $this->api;

        $this->scheduleAsyncScrobbleStart($mediaItem, $progressSecs, $accessToken, $api);
    }

    /**
     * B5: Schedule an async scrobble-start call.
     *
     * Uses Workerman\Timer::add(0, ...) to defer the blocking HTTP call to the
     * next event-loop tick so the Workerman worker returns to its loop
     * immediately. Falls back to synchronous execution when
     * Workerman\Timer is unavailable (unit tests, CLI).
     *
     * @param MediaItem $mediaItem Media item being played
     * @param int $progressSecs Current playback position in seconds
     * @param string $accessToken OAuth access token
     * @param TraktApi $api Trakt API client
     *
     * @return void
     */
    private function scheduleAsyncScrobbleStart(MediaItem $mediaItem, int $progressSecs, string $accessToken, TraktApi $api): void
    {
        if (class_exists(\Workerman\Timer::class)) {
            \Workerman\Timer::add(0, function () use ($mediaItem, $progressSecs, $accessToken, $api): void {
                $this->executeScrobbleStart($mediaItem, $progressSecs, $accessToken, $api);
            });
        } else {
            // Fallback: run synchronously (unit-test / CLI path)
            $this->executeScrobbleStart($mediaItem, $progressSecs, $accessToken, $api);
        }
    }

    /**
     * B5: Execute a scrobble-start call (called inside timer callback).
     *
     * Contains the full error-handling path: initial call, 401-triggered
     * refresh-then-retry, and final error logging.
     *
     * @param MediaItem $mediaItem Media item being played
     * @param int $progressSecs Current playback position in seconds
     * @param string $accessToken OAuth access token
     * @param TraktApi $api Trakt API client
     *
     * @return void
     */
    private function executeScrobbleStart(MediaItem $mediaItem, int $progressSecs, string $accessToken, TraktApi $api): void
    {
        try {
            $api->scrobbleStart($mediaItem, $progressSecs, $accessToken);

            $this->logger?->info('Trakt scrobble start submitted', [
                'title' => $mediaItem->name,
                'progress' => $progressSecs,
            ]);
        } catch (TraktAuthenticationException $e) {
            if ($this->refreshAfterAuthFailure($e, 'scrobble start')) {
                try {
                    $api->scrobbleStart($mediaItem, $progressSecs, $this->settings->accessToken ?? '');

                    $this->logger?->info('Trakt scrobble start submitted after refresh', [
                        'title' => $mediaItem->name,
                        'progress' => $progressSecs,
                    ]);
                } catch (TraktApiException $retry) {
                    $this->logger?->warning('Trakt: scrobble start failed after refresh retry', [
                        'error' => $retry->getMessage(),
                    ]);
                }
            }
        } catch (TraktApiException $e) {
            $this->logger?->warning('Trakt: scrobble start failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * B5: Schedule an async scrobble-stop call.
     *
     * Uses Workerman\Timer::add(0, ...) to defer the blocking HTTP call to the
     * next event-loop tick so the Workerman worker returns to its loop
     * immediately. Falls back to synchronous execution when
     * Workerman\Timer is unavailable (unit tests, CLI).
     *
     * @param MediaItem $mediaItem Media item that was played
     * @param int $progressSecs Final playback position in seconds
     * @param string $accessToken OAuth access token
     * @param TraktApi $api Trakt API client
     *
     * @return void
     */
    private function scheduleAsyncScrobbleStop(MediaItem $mediaItem, int $progressSecs, string $accessToken, TraktApi $api): void
    {
        if (class_exists(\Workerman\Timer::class)) {
            \Workerman\Timer::add(0, function () use ($mediaItem, $progressSecs, $accessToken, $api): void {
                $this->executeScrobbleStop($mediaItem, $progressSecs, $accessToken, $api);
            });
        } else {
            // Fallback: run synchronously (unit-test / CLI path)
            $this->executeScrobbleStop($mediaItem, $progressSecs, $accessToken, $api);
        }
    }

    /**
     * B5: Execute a scrobble-stop call (called inside timer callback).
     *
     * Contains the full error-handling path: initial call, 401-triggered
     * refresh-then-retry, and final error logging.
     *
     * @param MediaItem $mediaItem Media item that was played
     * @param int $progressSecs Final playback position in seconds
     * @param string $accessToken OAuth access token
     * @param TraktApi $api Trakt API client
     *
     * @return void
     */
    private function executeScrobbleStop(MediaItem $mediaItem, int $progressSecs, string $accessToken, TraktApi $api): void
    {
        try {
            $api->scrobbleStop($mediaItem, $progressSecs, $accessToken);

            $this->logger?->info('Trakt scrobble stop submitted', [
                'title' => $mediaItem->name,
                'progress' => $progressSecs,
            ]);
        } catch (TraktAuthenticationException $e) {
            if ($this->refreshAfterAuthFailure($e, 'scrobble stop')) {
                try {
                    $api->scrobbleStop($mediaItem, $progressSecs, $this->settings->accessToken ?? '');

                    $this->logger?->info('Trakt scrobble stop submitted after refresh', [
                        'title' => $mediaItem->name,
                        'progress' => $progressSecs,
                    ]);
                } catch (TraktApiException $retry) {
                    $this->logger?->warning('Trakt: scrobble stop failed after refresh retry', [
                        'error' => $retry->getMessage(),
                    ]);
                }
            }
        } catch (TraktApiException $e) {
            $this->logger?->warning('Trakt: scrobble stop failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * B6: Schedule an async scrobble-pause call.
     *
     * Uses Workerman\Timer::add(0, ...) to defer the blocking HTTP call to the
     * next event-loop tick so the Workerman worker returns to its loop
     * immediately. Falls back to synchronous execution when
     * Workerman\Timer is unavailable (unit tests, CLI).
     *
     * @param MediaItem $mediaItem Media item that was playing
     * @param int $progressSecs Current playback position in seconds
     * @param string $accessToken OAuth access token
     * @param TraktApi $api Trakt API client
     *
     * @return void
     */
    private function scheduleAsyncScrobblePause(MediaItem $mediaItem, int $progressSecs, string $accessToken, TraktApi $api): void
    {
        if (class_exists(\Workerman\Timer::class)) {
            \Workerman\Timer::add(0, function () use ($mediaItem, $progressSecs, $accessToken, $api): void {
                $this->executeScrobblePause($mediaItem, $progressSecs, $accessToken, $api);
            });
        } else {
            // Fallback: run synchronously (unit-test / CLI path)
            $this->executeScrobblePause($mediaItem, $progressSecs, $accessToken, $api);
        }
    }

    /**
     * B6: Execute a scrobble-pause call (called inside timer callback).
     *
     * @param MediaItem $mediaItem Media item that was playing
     * @param int $progressSecs Current playback position in seconds
     * @param string $accessToken OAuth access token
     * @param TraktApi $api Trakt API client
     *
     * @return void
     */
    private function executeScrobblePause(MediaItem $mediaItem, int $progressSecs, string $accessToken, TraktApi $api): void
    {
        try {
            $api->scrobblePause($mediaItem, $progressSecs, $accessToken);

            $this->logger?->info('Trakt scrobble pause submitted', [
                'title' => $mediaItem->name,
                'progress' => $progressSecs,
            ]);
        } catch (TraktAuthenticationException $e) {
            if ($this->refreshAfterAuthFailure($e, 'scrobble pause')) {
                try {
                    $api->scrobblePause($mediaItem, $progressSecs, $this->settings->accessToken ?? '');

                    $this->logger?->info('Trakt scrobble pause submitted after refresh', [
                        'title' => $mediaItem->name,
                        'progress' => $progressSecs,
                    ]);
                } catch (TraktApiException $retry) {
                    $this->logger?->warning('Trakt: scrobble pause failed after refresh retry', [
                        'error' => $retry->getMessage(),
                    ]);
                }
            }
        } catch (TraktApiException $e) {
            $this->logger?->warning('Trakt: scrobble pause failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get the current settings.
     *
     * @return TraktSettings
     *
     * @since 0.14.0
     */
    public function getSettings(): TraktSettings
    {
        return $this->settings;
    }

    /**
     * Get a REDACTED settings projection safe to return to the admin SPA.
     *
     * Never includes the raw OAuth token strings — only the editable
     * preferences plus a `has_tokens` connection flag and `token_expires_at`
     * status. Use this (NOT {@see TraktPlugin::getSettings()}) anywhere settings
     * are serialized for the admin Plugins page.
     *
     * @return array<string, mixed>
     *
     * @since 0.14.0
     */
    public function getSettingsForSpa(): array
    {
        return $this->settings->toSpaArray();
    }

    /**
     * Update the access token and persist it.
     *
     * The rebuilt settings are routed through the same persistence path as a
     * token refresh, so a manually-set access token is no longer dropped.
     *
     * @param string $token New access token
     *
     * @return void
     *
     * @since 0.14.0
     */
    public function setAccessToken(string $token): void
    {
        $this->settings = new TraktSettings(
            accessToken: $token,
            refreshToken: $this->settings->refreshToken,
            expiresAt: $this->settings->expiresAt,
            syncEnabled: $this->settings->syncEnabled,
            syncIntervalMinutes: $this->settings->syncIntervalMinutes,
            scrobbleEnabled: $this->settings->scrobbleEnabled,
            username: $this->settings->username,
        );

        $this->persistSettings();
    }

    /**
     * Update the refresh token and persist it.
     *
     * The rebuilt settings are routed through the same persistence path as a
     * token refresh, so a manually-set refresh token is no longer dropped.
     *
     * @param string $token New refresh token
     *
     * @return void
     *
     * @since 0.14.0
     */
    public function setRefreshToken(string $token): void
    {
        $this->settings = new TraktSettings(
            accessToken: $this->settings->accessToken,
            refreshToken: $token,
            expiresAt: $this->settings->expiresAt,
            syncEnabled: $this->settings->syncEnabled,
            syncIntervalMinutes: $this->settings->syncIntervalMinutes,
            scrobbleEnabled: $this->settings->scrobbleEnabled,
            username: $this->settings->username,
        );

        $this->persistSettings();
    }

    /**
     * Ensure the access token is fresh before an authenticated call.
     *
     * Single entrypoint for the OAuth refresh, wrapped by the per-account
     * single-flight gate (step B4). When the access token is expired
     * (per {@see TraktSettings::isTokenExpired()}) it requests a new token pair
     * from Trakt, rebuilds {@see TraktSettings} with the rotated
     * access/refresh tokens and a recomputed `expires_at` (= time() + expires_in),
     * assigns it, and persists it via {@see TraktPlugin::persistSettings()}.
     *
     * Concurrency: Trakt rotates the refresh token on every exchange, so two
     * concurrent callers must NOT both refresh with the same refresh token.
     * The FIRST caller acquires the single-flight gate and performs the
     * refresh; concurrent callers AWAIT it (yielding inside a coroutine) and
     * then re-evaluate expiry (double-checked locking) so they reuse the
     * freshly-rotated token instead of issuing a second, account-invalidating
     * refresh. The gate is always released (try/finally) — including when the
     * refresh throws — so a failed refresh never wedges future refreshes.
     *
     * @return bool True when a valid (fresh or freshly-refreshed) token is
     *              available; false when a refresh was needed but failed.
     *
     * @since 0.14.0
     */
    public function ensureFreshToken(): bool
    {
        if ($this->api === null) {
            return false;
        }

        // Fast path: token already valid — no refresh, no lock needed.
        if (!$this->settings->isTokenExpired()) {
            return true;
        }

        // Another caller is already refreshing: await it, then re-check.
        if ($this->refreshInProgress) {
            $this->awaitInFlightRefresh();

            // Double-checked locking: the in-flight refresh may have already
            // rotated the token. If so, reuse it instead of refreshing again —
            // this is the path that prevents a second account-invalidating
            // exchange of the same (now-stale) refresh token.
            if (!$this->settings->isTokenExpired()) {
                return true;
            }

            // Still expired. If a refresh is STILL in flight here, we were
            // re-entered synchronously (no coroutine to yield to) while the
            // owner is mid-exchange — do NOT start a nested refresh (that would
            // be the very double-refresh this gate exists to prevent). Report
            // failure; the owner will rotate the token momentarily.
            if ($this->refreshInProgress) {
                return false;
            }
            // The in-flight refresh has completed but failed (token still
            // expired, gate released). Fall through and attempt our own refresh.
        }

        // We are the single-flight owner for this refresh cycle.
        $this->beginRefresh();
        try {
            // Re-check under the gate in case a refresh completed between our
            // initial expiry check and acquiring ownership.
            if (!$this->settings->isTokenExpired()) {
                return true;
            }

            return $this->performTokenRefresh();
        } finally {
            $this->endRefresh();
        }
    }

    /**
     * Perform the actual OAuth refresh (no concurrency control of its own).
     *
     * This is the single refresh implementation wrapped by
     * {@see TraktPlugin::ensureFreshToken()}'s single-flight gate. It assumes
     * the caller has already confirmed the token is expired.
     *
     * @return bool True when the tokens were rotated; false when no refresh
     *              token is available or the exchange failed.
     */
    private function performTokenRefresh(): bool
    {
        if ($this->api === null) {
            return false;
        }

        $refreshToken = $this->settings->refreshToken;
        if ($refreshToken === null || $refreshToken === '') {
            $this->logger?->warning('Trakt: access token expired but no refresh token available');
            return false;
        }

        try {
            $tokens = $this->api->refreshAccessToken($refreshToken);
        } catch (TraktApiException $e) {
            $this->logger?->warning('Trakt: token refresh failed', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }

        $this->applyRefreshedTokens($tokens);

        $this->logger?->info('Trakt: access token refreshed');

        return true;
    }

    /**
     * Mark a refresh as in flight and arm the coroutine await primitive.
     *
     * Inside a Swoole coroutine a fresh, payload-free {@see \Swoole\Coroutine\Channel}
     * is created so concurrent coroutines can block (yield) on it until this
     * refresh completes. Outside a coroutine (unit tests, CLI) only the boolean
     * flag is set — there is no concurrency to await.
     *
     * @return void
     */
    private function beginRefresh(): void
    {
        $this->refreshInProgress = true;
        $this->refreshLock = $this->inCoroutine() ? new \Swoole\Coroutine\Channel(1) : null;
    }

    /**
     * Clear the in-flight flag and wake any awaiting coroutines.
     *
     * Always invoked from a `finally`, so a refresh that throws still releases
     * the gate. Closing the channel pushes EOF to every blocked `pop()` so each
     * awaiting coroutine resumes and re-checks expiry. The channel is then
     * dropped so the next refresh cycle starts from a clean primitive.
     *
     * @return void
     */
    private function endRefresh(): void
    {
        $this->refreshInProgress = false;

        $lock = $this->refreshLock;
        $this->refreshLock = null;
        if ($lock !== null) {
            // close() wakes all blocked pop()s with `false` (channel closed).
            $lock->close();
        }
    }

    /**
     * Await the currently in-flight refresh without busy-waiting.
     *
     * Inside a coroutine this pops the signalling channel, which YIELDS until
     * {@see TraktPlugin::endRefresh()} closes it (cooperative; never a blocking
     * sleep). The pop returns `false` on close — we only need the wake, not a
     * payload. Outside a coroutine (or if the owner already finished and
     * dropped the channel) there is nothing to await and we return immediately;
     * the caller's double-check then observes the rotated token.
     *
     * @return void
     */
    private function awaitInFlightRefresh(): void
    {
        $lock = $this->refreshLock;
        if ($lock === null || !$this->inCoroutine()) {
            return;
        }

        // Blocks (yields) until the owner closes the channel; the pop result is
        // intentionally ignored — closing is the wake signal.
        $lock->pop();
    }

    /**
     * Whether the current execution context is a Swoole coroutine.
     *
     * Guards every use of the coroutine await primitive so the gate degrades to
     * a plain in-process flag under PHPUnit/CLI (where Channel pop/push would
     * fatal outside a coroutine).
     *
     * @return bool
     */
    private function inCoroutine(): bool
    {
        return class_exists(\Swoole\Coroutine::class)
            && \Swoole\Coroutine::getCid() > 0;
    }

    /**
     * Recover from a 401 raised mid-scrobble by forcing a single token refresh.
     *
     * Unlike {@see TraktPlugin::ensureFreshToken()} this does not consult the
     * local expiry — a 401 means Trakt rejected the token regardless of what
     * the locally-cached `expires_at` says, so we attempt exactly one refresh
     * (rotating + persisting the tokens) and report whether the caller may
     * retry. The single-flight lock (B4) will wrap the same refresh path.
     *
     * @param TraktAuthenticationException $e The auth failure that was caught
     * @param string $context Human label for log context (e.g. "scrobble stop")
     *
     * @return bool True when a refresh succeeded and the caller should retry once.
     */
    private function refreshAfterAuthFailure(TraktAuthenticationException $e, string $context): bool
    {
        $this->logger?->warning('Trakt: ' . $context . ' failed (auth); attempting token refresh', [
            'error' => $e->getMessage(),
        ]);

        if ($this->api === null) {
            return false;
        }

        $refreshToken = $this->settings->refreshToken;
        if ($refreshToken === null || $refreshToken === '') {
            $this->logger?->warning('Trakt: cannot refresh after auth failure; no refresh token available');
            return false;
        }

        try {
            $tokens = $this->api->refreshAccessToken($refreshToken);
        } catch (TraktApiException $refreshError) {
            $this->logger?->warning('Trakt: token refresh after auth failure failed', [
                'error' => $refreshError->getMessage(),
            ]);
            return false;
        }

        $this->applyRefreshedTokens($tokens);
        $this->logger?->info('Trakt: token refreshed after auth failure');

        return true;
    }

    /**
     * Apply a refreshed token pair to the current settings and persist them.
     *
     * @param array<string, mixed> $tokens Token response with access_token,
     *                                     refresh_token, expires_in
     *
     * @return void
     */
    private function applyRefreshedTokens(array $tokens): void
    {
        $accessToken = is_string($tokens['access_token'] ?? null) && $tokens['access_token'] !== ''
            ? $tokens['access_token']
            : $this->settings->accessToken;
        $refreshToken = is_string($tokens['refresh_token'] ?? null) && $tokens['refresh_token'] !== ''
            ? $tokens['refresh_token']
            : $this->settings->refreshToken;
        $expiresIn = is_int($tokens['expires_in'] ?? null) ? $tokens['expires_in'] : 0;
        $expiresAt = $expiresIn > 0 ? time() + $expiresIn : $this->settings->expiresAt;

        $this->settings = new TraktSettings(
            accessToken: $accessToken,
            refreshToken: $refreshToken,
            expiresAt: $expiresAt,
            syncEnabled: $this->settings->syncEnabled,
            syncIntervalMinutes: $this->settings->syncIntervalMinutes,
            scrobbleEnabled: $this->settings->scrobbleEnabled,
            username: $this->settings->username,
        );

        $this->persistSettings();
    }

    /**
     * Persist the current settings via the host-provided writer.
     *
     * Tolerates the absence of a {@see TraktSettingsRepository}: when none was
     * injected/resolved it logs a warning and no-ops rather than crashing, so
     * the host is never forced to provide persistence.
     *
     * @return void
     */
    private function persistSettings(): void
    {
        if ($this->settingsRepository === null) {
            $this->logger?->warning('Trakt: no settings repository available; token changes not persisted');
            return;
        }

        if ($this->tokenCipher === null && $this->settings->hasTokens()) {
            // Graceful degrade (S1): no cipher/key available, so the tokens are
            // stored as-is. We still persist (losing tokens on restart would be
            // worse), but warn so the operator can configure a key.
            $this->logger?->warning('Trakt: no token cipher available; storing OAuth tokens unencrypted');
        }

        try {
            // Encrypt tokens at rest (S1): the storage payload carries ciphertext
            // for access/refresh tokens, never the raw long-lived credentials.
            $this->settingsRepository->save($this->settings->toStorageArray($this->tokenCipher));
        } catch (\Throwable $e) {
            $this->logger?->warning('Trakt: failed to persist settings', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Initialize the Trakt API client from current settings.
     *
     * @return void
     */
    private function initApi(): void
    {
        if ($this->api !== null) {
            return;
        }

        $config = $this->loadConfig();

        $clientId = is_string($config['client_id'] ?? null) ? $config['client_id'] : '';
        $clientSecret = is_string($config['client_secret'] ?? null) ? $config['client_secret'] : '';

        if ($clientId !== '' && $clientSecret !== '') {
            $this->api = new TraktApi(
                new HttpClient(),
                $clientId,
                $clientSecret,
                $this->logger
            );
        }
    }

    /**
     * Whether the plugin has all required configuration.
     *
     * @return bool
     */
    private function isConfigured(): bool
    {
        return $this->enabled
            && $this->settings->isConfigured()
            && $this->api !== null;
    }

    /**
     * Look up a media item by ID.
     *
     * @param string $mediaItemId Media item UUID
     *
     * @return MediaItem|null
     */
    private function findMediaItem(string $mediaItemId): ?MediaItem
    {
        if ($this->itemRepository === null) {
            return null;
        }

        $row = $this->itemRepository->findById($mediaItemId);
        if ($row === null) {
            return null;
        }

        return MediaItem::fromRow($row);
    }

    /**
     * Sync completed playback to Trakt history.
     *
     * @param string $mediaItemId Media item ID
     * @param int $positionTicks Final position
     *
     * @return void
     */
    private function syncToTrakt(string $mediaItemId, int $positionTicks): void
    {
        if ($this->watchHistory === null || $this->api === null || $this->db === null) {
            return;
        }

        $entry = $this->watchHistory->getForMediaItem('default', $mediaItemId);
        $progress = $entry['progress_percent'] ?? 0;
        if ($entry === null || !is_numeric($progress) || (float) $progress < 90.0) {
            return;
        }

        $sync = new TraktHistorySync(
            $this->api,
            $this->watchHistory,
            $this->settings,
            $this->db,
            $this->logger
        );

        $lastWatchedAt = $entry['last_watched_at'] ?? 'now';
        $durationTicks = $entry['duration_ticks'] ?? null;

        $sync->syncPhlixToTrakt(
            $mediaItemId,
            is_string($lastWatchedAt) ? $lastWatchedAt : 'now',
            $positionTicks,
            is_numeric($durationTicks) ? (int) $durationTicks : null
        );
    }

    /**
     * Resolve the configured sync interval, in seconds.
     *
     * The `sync_interval_minutes` setting was parsed into
     * {@see TraktSettings::$syncIntervalMinutes} and then IGNORED — the timer used
     * the hardcoded {@see self::SYNC_INTERVAL_SEC} constant, so changing the
     * setting in the admin UI did nothing. This makes it real.
     *
     * Clamped to a one-minute floor: the value reaches us from admin input, and a
     * zero or negative interval would arm a runaway timer inside a resident
     * Workerman worker.
     */
    private function syncIntervalSeconds(): int
    {
        $minutes = $this->settings->syncIntervalMinutes;

        return $minutes >= 1 ? $minutes * 60 : self::SYNC_INTERVAL_SEC;
    }

    /**
     * B8: Schedule periodic Trakt→Phlix history sync.
     *
     * Uses Workerman\Timer::add() to run the sync every
     * {@see self::syncIntervalSeconds()}. Gracefully no-ops when
     * Workerman\Timer is unavailable (unit tests, CLI) or when sync is disabled.
     *
     * @param ContainerInterface $container Host PSR-11 container
     *
     * @return void
     */
    private function schedulePeriodicSync(ContainerInterface $container): void
    {
        if (!$this->settings->isConfigured() || !$this->settings->syncEnabled) {
            return;
        }

        try {
            \Workerman\Timer::add($this->syncIntervalSeconds(), function (): void {
                $this->runScheduledSync();
            });
        } catch (\Throwable) {
            // Timer not available outside Workerman process (unit tests, CLI)
        }
    }

    /**
     * B8: Execute the periodic Trakt→Phlix sync.
     *
     * Called by the Workerman timer on each tick. Catches all exceptions so a
     * failed sync does not crash the worker.
     *
     * @return void
     */
    private function runScheduledSync(): void
    {
        if ($this->watchHistory === null || $this->api === null || $this->db === null) {
            return;
        }

        if (!$this->settings->isConfigured() || !$this->settings->syncEnabled) {
            return;
        }

        try {
            $sync = new TraktHistorySync(
                $this->api,
                $this->watchHistory,
                $this->settings,
                $this->db,
                $this->logger
            );
            // Use 'default' profile for now (per-profile sync is future work)
            $sync->syncTraktToPhlix('default');
        } catch (\Throwable $e) {
            $this->logger?->warning('Trakt periodic sync failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Load Trakt plugin configuration.
     *
     * @return array<string, mixed>
     */
    private function loadConfig(): array
    {
        $configFile = dirname(__DIR__, 5) . '/config/scrobblers/trakt.php';

        if (is_file($configFile)) {
            /** @var array<string, mixed> $config */
            $config = include $configFile;

            return $config;
        }

        return [];
    }
}
