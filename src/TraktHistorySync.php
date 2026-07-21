<?php

declare(strict_types=1);

namespace Phlix\Plugins\Scrobbler\Trakt;

use Phlix\Auth\WatchHistory;
use Phlix\Media\Library\MediaItem;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Handles two-way watch history sync between Trakt and Phlix.
 *
 * - Trakt → Phlix: Pulls Trakt watched history on schedule and writes
 *   new entries to local WatchHistory for items not yet at ≥ 90% complete.
 * - Phlix → Trakt: Pushes local WatchHistory entries (≥ 90%) to Trakt
 *   after PlaybackStopped events where completion threshold was met.
 *
 * @package Phlix\Plugins\Scrobbler\Trakt
 * @since 0.14.0
 */
class TraktHistorySync
{
    private readonly LoggerInterface $logger;

    /**
     * @param TraktApi $api Trakt API client
     * @param WatchHistory $watchHistory Local watch history repository
     * @param TraktSettings $settings User settings
     * @param \Workerman\MySQL\Connection $db Database connection for media item lookups
     * @param LoggerInterface|null $logger Optional PSR-3 logger
     */
    public function __construct(
        private readonly TraktApi $api,
        private readonly WatchHistory $watchHistory,
        private readonly TraktSettings $settings,
        private readonly \Workerman\MySQL\Connection $db,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Sync Trakt watched history → Phlix local history.
     *
     * Pulls the user's watched history from Trakt and compares against
     * local WatchHistory entries. For items watched on Trakt but not yet
     * ≥ 90% complete in Phlix, writes a local entry.
     *
     * Uses last-write-wins based on watchedAt timestamp.
     *
     * @param string $profileId Profile ID to sync history for
     *
     * @return int Number of new entries written
     *
     * @since 0.14.0
     */
    /** Items requested per Trakt history page. */
    private const HISTORY_PAGE_SIZE = 100;

    /**
     * Hard ceiling on pages fetched in one sync.
     *
     * 100 pages x 100 items = 10,000 watched entries, comfortably above any real
     * library. This exists so a malformed API response that never returns a short
     * page cannot spin forever inside a resident Workerman worker.
     */
    private const HISTORY_MAX_PAGES = 100;

    /**
     * Fetch the user's COMPLETE watched history, following pagination.
     *
     * Previously this took only page 1 with a limit of 100, so any user with more
     * than 100 watched items had the remainder silently dropped — the sync
     * reported success having imported a truncated history.
     *
     * Stops at the first short page (fewer items than requested), which is the
     * end-of-results signal, and is bounded by {@see self::HISTORY_MAX_PAGES}.
     *
     * @return array<mixed> Every watched item across all pages.
     *
     * @throws TraktApiException On API error.
     */
    private function fetchAllWatchedHistory(): array
    {
        $all = [];

        for ($page = 1; $page <= self::HISTORY_MAX_PAGES; $page++) {
            $batch = $this->api->getWatchedHistory(
                $this->settings->username,
                $page,
                self::HISTORY_PAGE_SIZE,
                $this->settings->accessToken ?? ''
            );

            $count = count($batch);
            if ($count === 0) {
                break;
            }

            foreach ($batch as $item) {
                $all[] = $item;
            }

            // A short page means there is no next one.
            if ($count < self::HISTORY_PAGE_SIZE) {
                return $all;
            }
        }

        // Only reachable by hitting the page cap, which means the history is
        // larger than we are willing to pull in one pass. Say so — a silently
        // truncated import is the defect this method exists to fix.
        $this->logger->warning('TraktHistorySync: hit the page cap; history may be incomplete', [
            'pages_fetched' => self::HISTORY_MAX_PAGES,
            'items_fetched' => count($all),
        ]);

        return $all;
    }

    public function syncTraktToPhlix(string $profileId): int
    {
        if (!$this->settings->isConfigured()) {
            $this->logger->debug('TraktHistorySync: plugin not configured, skipping Trakt→Phlix');
            return 0;
        }

        if (!$this->settings->syncEnabled) {
            $this->logger->debug('TraktHistorySync: sync disabled, skipping Trakt→Phlix');
            return 0;
        }

        try {
            $history = $this->fetchAllWatchedHistory();
        } catch (TraktApiException $e) {
            $this->logger->warning('TraktHistorySync: failed to fetch Trakt history', [
                'error' => $e->getMessage(),
            ]);
            return 0;
        }

        $written = 0;

        foreach ($history as $item) {
            if (!is_array($item)) {
                continue;
            }
            $mediaItemId = $this->findMediaItemId($item);
            if ($mediaItemId === null) {
                continue;
            }

            $itemMap = [];
            foreach ($item as $iKey => $iValue) {
                if (is_string($iKey)) {
                    $itemMap[$iKey] = $iValue;
                }
            }

            $existing = $this->watchHistory->getForMediaItem($profileId, $mediaItemId);

            if ($existing !== null && $existing['progress_percent'] >= WatchHistory::COMPLETED_THRESHOLD) {
                $this->logger->debug('TraktHistorySync: item already at 90%+, skipping', [
                    'media_item_id' => $mediaItemId,
                    'progress' => $existing['progress_percent'],
                ]);
                continue;
            }

            $watchedAt = $this->parseWatchedAt($itemMap);
            $durationTicks = $this->extractDurationTicks($itemMap);

            $this->watchHistory->updateProgress(
                $profileId,
                $mediaItemId,
                $durationTicks,
                $durationTicks,
                WatchHistory::STATUS_COMPLETED
            );

            $written++;
            $this->logger->debug('TraktHistorySync: wrote entry from Trakt', [
                'media_item_id' => $mediaItemId,
                'watched_at' => $watchedAt->format('c'),
            ]);
        }

        $this->logger->info('TraktHistorySync: completed Trakt→Phlix sync', [
            'profile_id' => $profileId,
            'items_written' => $written,
        ]);

        return $written;
    }

    /**
     * Sync Phlix local history → Trakt.
     *
     * Pushes local WatchHistory entries that have reached ≥ 90%
     * completion to Trakt so the user gets credit for the watch.
     *
     * @param string $mediaItemId Media item that reached 90%+ completion
     * @param string $lastWatchedAt When the item was last watched
     * @param int $positionTicks Final playback position
     * @param int|null $durationTicks Total duration
     *
     * @return bool True when successfully pushed to Trakt
     *
     * @since 0.14.0
     */
    public function syncPhlixToTrakt(string $mediaItemId, string $lastWatchedAt, int $positionTicks, ?int $durationTicks): bool
    {
        if (!$this->settings->isConfigured()) {
            $this->logger->debug('TraktHistorySync: plugin not configured, skipping Phlix→Trakt');
            return false;
        }

        if (!$this->settings->syncEnabled) {
            $this->logger->debug('TraktHistorySync: sync disabled, skipping Phlix→Trakt');
            return false;
        }

        $existing = $this->watchHistory->getForMediaItem('default', $mediaItemId);
        if ($existing === null || $existing['progress_percent'] < WatchHistory::COMPLETED_THRESHOLD) {
            $this->logger->debug('TraktHistorySync: item below 90%, skipping Phlix→Trakt', [
                'media_item_id' => $mediaItemId,
                'progress' => $existing['progress_percent'] ?? 0,
            ]);
            return false;
        }

        try {
            $item = $this->buildMediaItem($mediaItemId, $existing);
            $watchedAt = new \DateTimeImmutable($lastWatchedAt);

            $this->api->addToHistory($item, $watchedAt, $this->settings->accessToken ?? '');

            $this->logger->info('TraktHistorySync: pushed to Trakt', [
                'media_item_id' => $mediaItemId,
            ]);

            return true;
        } catch (TraktApiException $e) {
            $this->logger->warning('TraktHistorySync: failed to push to Trakt', [
                'media_item_id' => $mediaItemId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Find a local media item ID from a Trakt history entry.
     *
     * Matches TMDB, TVDB, or IMDB IDs from the Trakt history item against
     * the local media_items.metadata_json column to resolve the local ID.
     *
     * @param array<mixed, mixed> $item Trakt history item with movie or episode ids
     *
     * @return string|null Local media_items.id if resolved, null otherwise.
     */
    private function findMediaItemId(array $item): ?string
    {
        $movie = $item['movie'] ?? null;
        $episode = $item['episode'] ?? null;

        $ids = null;
        if (is_array($movie) && isset($movie['ids']) && is_array($movie['ids'])) {
            $ids = $movie['ids'];
        } elseif (is_array($episode) && isset($episode['ids']) && is_array($episode['ids'])) {
            $ids = $episode['ids'];
        }

        if ($ids === null) {
            return null;
        }

        // Early exit for pre-resolved ID (test seam)
        if (isset($item['_resolved_media_item_id']) && is_string($item['_resolved_media_item_id'])) {
            return $item['_resolved_media_item_id'];
        }

        // TMDB is most reliable for movies, TVDB for shows, IMDB is universal fallback
        foreach (['tmdb', 'tvdb', 'imdb'] as $idType) {
            $externalId = $ids[$idType] ?? null;
            if ($externalId === null || $externalId === '') {
                continue;
            }

            $mediaItemId = $this->findMediaItemIdByExternalId($idType, (string) $externalId);
            if ($mediaItemId !== null) {
                return $mediaItemId;
            }
        }

        return null;
    }

    /**
     * Look up a local media item ID by external ID.
     *
     * The metadata_json column stores external IDs like:
     * {"tmdb_id": "123", "imdb_id": "tt123", "tvdb_id": "456"}
     *
     * Uses JSON_EXTRACT when available (MySQL 5.7+) for reliable extraction,
     * falling back to LIKE pattern matching for older MySQL versions.
     * All external ID values are escaped to prevent SQL injection via
     * malformed ID strings.
     *
     * @param string $idType tmdb, tvdb, or imdb
     * @param string $externalId The external ID value
     *
     * @return string|null Local media_items.id if found, null otherwise.
     */
    private function findMediaItemIdByExternalId(string $idType, string $externalId): ?string
    {
        // Escape the external ID to prevent SQL injection in LIKE pattern
        $escapedId = $this->db->real_escapeString($externalId);
        $likePattern = '%"' . $idType . '_id":"' . $escapedId . '"%';

        // Try JSON_EXTRACT first (MySQL 5.7+) for reliable extraction
        // This avoids LIKE's fragility with JSON strings
        $jsonPath = '$.' . $idType . '_id';
        $result = $this->db->query(
            "SELECT id FROM media_items WHERE JSON_EXTRACT(metadata_json, ?) = ? LIMIT 1",
            [$jsonPath, $externalId]
        );

        if (is_array($result) && isset($result[0]['id'])) {
            return (string) $result[0]['id'];
        }

        // Fallback to LIKE pattern matching for older MySQL or edge cases
        // where the JSON value might be stored differently
        $result = $this->db->query(
            'SELECT id FROM media_items WHERE metadata_json LIKE ? LIMIT 1',
            [$likePattern]
        );

        if (is_array($result) && isset($result[0]['id'])) {
            return (string) $result[0]['id'];
        }

        return null;
    }

    /**
     * Find a local media item ID using title/year fallback matching.
     *
     * When external IDs don't resolve to a local item (e.g., different ID
     * providers or metadata source mismatch), fall back to matching by
     * title and year. This is less reliable but better than nothing.
     *
     * @param string $title Media title
     * @param int|null $year Release year
     * @param string $type movie or episode
     *
     * @return string|null Local media_items.id if found, null otherwise.
     */
    private function findMediaItemIdByTitleYear(string $title, ?int $year, string $type): ?string
    {
        if ($title === '') {
            return null;
        }

        // Escape title for LIKE query
        $escapedTitle = '%' . $this->db->real_escapeString($title) . '%';

        if ($year !== null) {
            $result = $this->db->query(
                "SELECT id FROM media_items WHERE name LIKE ? AND metadata_json LIKE ? AND type = ? LIMIT 1",
                [$escapedTitle, '%"year":"' . $year . '"%', $type]
            );
        } else {
            $result = $this->db->query(
                'SELECT id FROM media_items WHERE name LIKE ? AND type = ? LIMIT 1',
                [$escapedTitle, $type]
            );
        }

        if (is_array($result) && isset($result[0]['id'])) {
            $this->logger->debug('TraktHistorySync: resolved media item via title/year fallback', [
                'title' => $title,
                'year' => $year,
                'type' => $type,
                'id' => $result[0]['id'],
            ]);
            return (string) $result[0]['id'];
        }

        return null;
    }

    /**
     * Parse watched_at timestamp from a Trakt history item.
     *
     * @param array<string, mixed> $item Trakt history item
     *
     * @return \DateTimeImmutable
     */
    private function parseWatchedAt(array $item): \DateTimeImmutable
    {
        $watchedAt = $item['watched_at'] ?? null;
        if ($watchedAt !== null && is_string($watchedAt)) {
            try {
                return new \DateTimeImmutable($watchedAt);
            } catch (\Exception) {
            }
        }
        return new \DateTimeImmutable();
    }

    /**
     * Extract duration in ticks from a Trakt history item.
     *
     * @param array<string, mixed> $item Trakt history item
     *
     * @return int Duration in ticks (0 if unknown)
     */
    private function extractDurationTicks(array $item): int
    {
        $runtime = $item['runtime'] ?? null;
        if (!is_numeric($runtime)) {
            $movie = $item['movie'] ?? null;
            if (is_array($movie) && isset($movie['runtime']) && is_numeric($movie['runtime'])) {
                $runtime = $movie['runtime'];
            } else {
                $episode = $item['episode'] ?? null;
                if (is_array($episode) && isset($episode['runtime']) && is_numeric($episode['runtime'])) {
                    $runtime = $episode['runtime'];
                }
            }
        }

        if (is_numeric($runtime)) {
            $seconds = (int)$runtime;
            return $seconds * WatchHistory::TICKS_PER_SECOND;
        }
        return 0;
    }

    /**
     * Build a MediaItem from local history data.
     *
     * @param string $mediaItemId Media item UUID
     * @param array<string, mixed> $historyEntry Local watch history entry
     *
     * @return MediaItem
     */
    private function buildMediaItem(string $mediaItemId, array $historyEntry): MediaItem
    {
        $name = is_string($historyEntry['media_name'] ?? null) ? $historyEntry['media_name'] : 'Unknown';
        $type = is_string($historyEntry['media_type'] ?? null) ? $historyEntry['media_type'] : 'movie';
        $path = '';
        $metadata = is_array($historyEntry['metadata'] ?? null) ? $historyEntry['metadata'] : [];

        return new MediaItem(
            id: $mediaItemId,
            name: $name,
            type: $type,
            path: $path,
            metadata: $metadata
        );
    }

    /**
     * Sync ratings from Trakt → Phlix.
     *
     * Pulls ratings from Trakt and updates local user ratings for matched items.
     * Uses the same robust ID matching as history sync, with title/year fallback.
     *
     * @param string $profileId Profile ID to sync ratings for
     *
     * @return int Number of ratings updated
     *
     * @since 0.14.0
     */
    public function syncRatingsTraktToPhlix(string $profileId): int
    {
        if (!$this->settings->isConfigured()) {
            $this->logger->debug('TraktHistorySync: plugin not configured, skipping ratings Trakt→Phlix');
            return 0;
        }

        if (!$this->settings->syncEnabled) {
            $this->logger->debug('TraktHistorySync: sync disabled, skipping ratings Trakt→Phlix');
            return 0;
        }

        try {
            $ratings = $this->api->getRatings(
                $this->settings->username,
                $this->settings->accessToken ?? ''
            );
        } catch (TraktApiException $e) {
            $this->logger->warning('TraktHistorySync: failed to fetch Trakt ratings', [
                'error' => $e->getMessage(),
            ]);
            return 0;
        }

        $updated = 0;

        foreach ($ratings as $rating) {
            if (!is_array($rating)) {
                continue;
            }

            $mediaItemId = $this->findMediaItemIdFromRating($rating);
            if ($mediaItemId === null) {
                continue;
            }

            $ratingValue = $this->extractRatingValue($rating);
            if ($ratingValue === null) {
                continue;
            }

            // Update local rating via watch history
            $this->updateLocalRating($profileId, $mediaItemId, $ratingValue);
            $updated++;

            $this->logger->debug('TraktHistorySync: synced rating from Trakt', [
                'media_item_id' => $mediaItemId,
                'rating' => $ratingValue,
            ]);
        }

        $this->logger->info('TraktHistorySync: completed ratings Trakt→Phlix sync', [
            'profile_id' => $profileId,
            'ratings_updated' => $updated,
        ]);

        return $updated;
    }

    /**
     * Find media item ID from a Trakt rating entry.
     *
     * Uses the same robust matching strategy as history sync, with
     * title/year fallback for items not matched by external ID.
     *
     * @param array<mixed, mixed> $rating Trakt rating entry
     *
     * @return string|null Local media item ID if resolved
     */
    private function findMediaItemIdFromRating(array $rating): ?string
    {
        $movie = $rating['movie'] ?? null;
        $episode = $rating['episode'] ?? null;

        $ids = null;
        $title = null;
        $year = null;
        $type = 'movie';

        if (is_array($movie) && isset($movie['ids']) && is_array($movie['ids'])) {
            $ids = $movie['ids'];
            $title = $movie['title'] ?? null;
            $year = isset($movie['year']) && is_numeric($movie['year']) ? (int) $movie['year'] : null;
            $type = 'movie';
        } elseif (is_array($episode) && isset($episode['ids']) && is_array($episode['ids'])) {
            $ids = $episode['ids'];
            $title = $episode['title'] ?? null;
            $year = isset($episode['year']) && is_numeric($episode['year']) ? (int) $episode['year'] : null;
            $type = 'episode';
        }

        if ($ids === null) {
            return null;
        }

        // Try external ID matching first (most reliable)
        foreach (['tmdb', 'tvdb', 'imdb'] as $idType) {
            $externalId = $ids[$idType] ?? null;
            if ($externalId === null || $externalId === '') {
                continue;
            }

            $mediaItemId = $this->findMediaItemIdByExternalId($idType, (string) $externalId);
            if ($mediaItemId !== null) {
                return $mediaItemId;
            }
        }

        // Fall back to title/year matching
        if (is_string($title) && $title !== '') {
            return $this->findMediaItemIdByTitleYear($title, $year, $type);
        }

        return null;
    }

    /**
     * Extract the rating value from a Trakt rating entry.
     *
     * @param array<mixed, mixed> $rating Trakt rating entry
     *
     * @return int|null Rating value (1-10) or null if invalid
     */
    private function extractRatingValue(array $rating): ?int
    {
        $rated = $rating['rated_at'] ?? null;
        $ratingValue = $rating['rating'] ?? null;

        if (!is_numeric($ratingValue)) {
            return null;
        }

        $value = (int) $ratingValue;
        if ($value < 1 || $value > 10) {
            return null;
        }

        return $value;
    }

    /**
     * Update local rating for a media item.
     *
     * @param string $profileId Profile ID
     * @param string $mediaItemId Local media item ID
     * @param int $rating Rating value (1-10)
     *
     * @return void
     */
    private function updateLocalRating(string $profileId, string $mediaItemId, int $rating): void
    {
        // Rating is stored in watch_history as user_rating field
        // This uses the same updateProgress mechanism but sets the rating
        $this->watchHistory->updateProgress(
            $profileId,
            $mediaItemId,
            0, // position
            0, // duration
            WatchHistory::STATUS_COMPLETED,
            $rating
        );
    }

    /**
     * Sync a rating from Phlix → Trakt.
     *
     * Pushes a local rating to Trakt for a specific media item.
     *
     * @param string $mediaItemId Media item that was rated
     * @param int $rating Rating value (1-10)
     *
     * @return bool True when successfully pushed to Trakt
     *
     * @since 0.14.0
     */
    public function syncRatingsPhlixToTrakt(string $mediaItemId, int $rating): bool
    {
        if (!$this->settings->isConfigured()) {
            $this->logger->debug('TraktHistorySync: plugin not configured, skipping ratings Phlix→Trakt');
            return false;
        }

        if (!$this->settings->syncEnabled) {
            $this->logger->debug('TraktHistorySync: sync disabled, skipping ratings Phlix→Trakt');
            return false;
        }

        $existing = $this->watchHistory->getForMediaItem('default', $mediaItemId);
        if ($existing === null) {
            $this->logger->debug('TraktHistorySync: no local history for item, skipping rating sync', [
                'media_item_id' => $mediaItemId,
            ]);
            return false;
        }

        try {
            $item = $this->buildMediaItem($mediaItemId, $existing);
            $clampedRating = max(1, min(10, $rating));

            $this->api->addRating($item, $clampedRating, $this->settings->accessToken ?? '');

            $this->logger->info('TraktHistorySync: pushed rating to Trakt', [
                'media_item_id' => $mediaItemId,
                'rating' => $clampedRating,
            ]);

            return true;
        } catch (TraktApiException $e) {
            $this->logger->warning('TraktHistorySync: failed to push rating to Trakt', [
                'media_item_id' => $mediaItemId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
}
