<?php

declare(strict_types=1);

namespace Phlix\Plugins\Scrobbler\Trakt;

/**
 * Local metadata duration sizing, shared by the scrobble path and the
 * history pull.
 *
 * Phlix stores a media item's length in `media_items.metadata_json` as
 * either `duration_seconds` (canonical, from the local probe) or `runtime`
 * (TMDB minutes, from metadata providers). The plugin resolves playback
 * durations from that pair in {@see TraktPlugin::resolveDurationSeconds()};
 * the Trakt→Phlix history pull needs the same reading for completed rows
 * whose Trakt entry carries no runtime (review trakt__srphp #3 / PG-127),
 * so the rule lives here once instead of a second copy drifting.
 *
 * @package Phlix\Plugins\Scrobbler\Trakt
 */
final class DurationSizing
{
    /**
     * Resolve a duration in seconds from a metadata array.
     *
     * `duration_seconds` wins; a positive TMDB-style `runtime` (whole
     * minutes) is the fallback. Anything else yields 0.0 — callers must
     * treat 0.0 as "unknown" and never write it as a real duration.
     *
     * @param array<string, mixed> $metadata Media item metadata
     *
     * @return float Duration in seconds (0.0 if unknown)
     */
    public static function secondsFromMetadata(array $metadata): float
    {
        $durationSeconds = $metadata['duration_seconds'] ?? null;
        if (is_numeric($durationSeconds) && (float) $durationSeconds > 0.0) {
            return (float) $durationSeconds;
        }

        // TMDB runtime is expressed in whole minutes.
        $runtime = $metadata['runtime'] ?? null;
        if (is_numeric($runtime) && (float) $runtime > 0.0) {
            return (float) $runtime * 60.0;
        }

        return 0.0;
    }
}
