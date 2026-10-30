<?php

declare(strict_types=1);

namespace Phlix\Plugins\Scrobbler\Trakt;

/**
 * Marker interface for Trakt scrobbler plugin entry classes.
 *
 * Documentation-grade marker only: it names the classes in this codebase
 * that provide Trakt.tv integration. The host plugin loader does NOT
 * recognize plugins through it — discovery is the manifest `entry` class
 * plus the `LifecycleInterface`/`subscribedEvents()` contract the server's
 * PluginLoader consumes (zero references to this interface exist
 * host-side). Renaming or removing it would not change what loads.
 *
 * @package Phlix\Plugins\Scrobbler\Trakt
 * @since 1.1.0
 */
interface TraktPluginInterface
{
}
