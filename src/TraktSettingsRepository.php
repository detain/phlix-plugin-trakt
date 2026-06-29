<?php

declare(strict_types=1);

namespace Phlix\Plugins\Scrobbler\Trakt;

/**
 * Persistence seam for Trakt scrobbler settings.
 *
 * The host application provides an implementation (resolvable from the
 * PSR-11 container) that writes the supplied settings map back to wherever
 * plugin settings live (e.g. the `plugins.settings_json` column). The plugin
 * uses this to persist rotated OAuth tokens after a refresh so the new
 * access/refresh tokens survive a restart.
 *
 * Implementations should accept the same key shape produced by
 * {@see TraktSettings::toArray()}.
 *
 * @package Phlix\Plugins\Scrobbler\Trakt
 * @since 0.14.0
 */
interface TraktSettingsRepository
{
    /**
     * Persist the given Trakt settings map.
     *
     * @param array<string, mixed> $settings Settings to persist
     *                                        (shape: {@see TraktSettings::toArray()})
     *
     * @return void
     *
     * @since 0.14.0
     */
    public function save(array $settings): void;
}
