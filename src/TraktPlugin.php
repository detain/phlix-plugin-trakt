<?php

declare(strict_types=1);

namespace Phlix\Plugins\Scrobbler\Trakt;

use Phlix\Auth\WatchHistory;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Library\MediaItem;
use Phlix\Shared\Plugin\LifecycleInterface;
use Phlix\Shared\Events\Playback\PlaybackStarted;
use Phlix\Shared\Events\Playback\PlaybackStopped;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Trakt.tv scrobbler plugin entry class.
 *
 * Subscribes to PlaybackStarted and PlaybackStopped events and submits
 * scrobble data to Trakt.tv using the 3-state scrobble protocol (start/stop).
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
 * @package Phlix\Plugins\Scrobbler\Trakt
 * @since 0.14.0
 */
final class TraktPlugin implements LifecycleInterface
{
    /**
     * Plugin type identifier used in the plugin manifest.
     */
    public const PLUGIN_TYPE = 'scrobbler';

    private ?ItemRepository $itemRepository = null;
    private ?WatchHistory $watchHistory = null;
    private ?LoggerInterface $logger = null;
    private ?TraktApi $api = null;

    /**
     * Writer used to persist rotated tokens. Optional: the host may not
     * provide one, in which case persistence is a no-op (with a warning).
     */
    private ?TraktSettingsRepository $settingsRepository = null;

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
     */
    public function __construct(
        ?TraktSettings $settings = null,
        ?LoggerInterface $logger = null,
        ?TraktApi $api = null,
        ?TraktSettingsRepository $settingsRepository = null,
    ) {
        $this->settings = $settings ?? new TraktSettings();
        $this->logger = $logger ?? new NullLogger();
        $this->api = $api;
        $this->settingsRepository = $settingsRepository;
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
        $this->settings = TraktSettings::fromArray($settings);
        $this->enabled = ($settings['enabled'] ?? false) === true;
    }

    /**
     * @param ContainerInterface $container Host PSR-11 container
     *
     * @return void
     *
     * @since 0.14.0
     */
    public function onEnable(ContainerInterface $container): void
    {
        if ($this->logger instanceof NullLogger) {
            $logger = $container->get(LoggerInterface::class);
            $this->logger = $logger instanceof LoggerInterface ? $logger : new NullLogger();
        }

        $itemRepo = $container->get(ItemRepository::class);
        $this->itemRepository = $itemRepo instanceof ItemRepository ? $itemRepo : null;

        $watchHist = $container->get(WatchHistory::class);
        $this->watchHistory = $watchHist instanceof WatchHistory ? $watchHist : null;

        $this->resolveSettingsRepository($container);

        $this->initApi();
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

        $this->ensureFreshToken();

        /** @var TraktApi */
        $api = $this->api;

        try {
            $api->scrobbleStart($mediaItem, $progressSecs, $this->settings->accessToken ?? '');

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

        $this->ensureFreshToken();

        /** @var TraktApi */
        $api = $this->api;

        try {
            $api->scrobbleStop($mediaItem, $progressSecs, $this->settings->accessToken ?? '');

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

        if ($event->reachedEnd && $this->watchHistory !== null) {
            $this->syncToTrakt($event->mediaItemId, $event->finalPositionTicks);
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

        try {
            $this->settingsRepository->save($this->settings->toArray());
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
        if ($this->watchHistory === null || $this->api === null) {
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
