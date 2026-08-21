<?php

declare(strict_types=1);

namespace Workerman;

/**
 * Minimal stub for the Workerman Worker class.
 *
 * Only the static $globalEvent marker is modelled: HttpClient checks it to
 * decide whether a Workerman event loop is running and the async request path
 * can yield to it. The real class lives in the phlix-server runtime and is not
 * part of this plugin's dependency closure.
 */
class Worker
{
    /**
     * The running event loop, or null when no loop is active.
     *
     * @var object|null
     */
    public static $globalEvent = null;
}
