<?php

declare(strict_types=1);

// Load the composer autoloader
require_once __DIR__ . '/../vendor/autoload.php';

// Load Workerman MySQL Connection stub for testing
require_once __DIR__ . '/stubs/Workerman/MySQL/Connection.php';

/**
 * Stub for host-supplied Phlix\Auth\WatchHistory class.
 *
 * The real WatchHistory lives in the Phlix server and is not part of this
 * plugin's dependency closure. We register a minimal stub so that
 * TraktHistorySync can be instantiated in unit tests.
 *
 * NOTE: This class must NOT be final because PHPUnit needs to create a mock.
 */
if (!class_exists(\Phlix\Auth\WatchHistory::class)) {
    class WatchHistoryStub
    {
        public const COMPLETED_THRESHOLD = 90.0;
        public const STATUS_COMPLETED = 'completed';
        public const STATUS_IN_PROGRESS = 'in_progress';
        public const TICKS_PER_SECOND = 10_000_000;

        public function getForMediaItem(string $profileId, string $mediaItemId): ?array
        {
            return null;
        }

        public function updateProgress(
            string $profileId,
            string $mediaItemId,
            int $positionTicks,
            int $durationTicks,
            string $status
        ): void {
        }
    }

    class_alias(WatchHistoryStub::class, \Phlix\Auth\WatchHistory::class);
}

/**
 * Stub for host-supplied Phlix\Media\Library\MediaItem class.
 *
 * Mirrors the pattern used in TraktApiTest for MediaItemStub.
 */
if (!class_exists(\Phlix\Media\Library\MediaItem::class)) {
    final class MediaItemStubForHistory
    {
        public function __construct(
            public string $id,
            public string $name,
            public string $type,
            public string $path,
            public array $metadata = [],
        ) {
        }

        public static function fromRow(array $row): self
        {
            $metadata = is_array($row['metadata'] ?? null) ? $row['metadata'] : [];

            return new self(
                id: is_string($row['id'] ?? null) ? $row['id'] : '',
                name: is_string($row['name'] ?? null) ? $row['name'] : '',
                type: is_string($row['type'] ?? null) ? $row['type'] : 'movie',
                path: is_string($row['path'] ?? null) ? $row['path'] : '',
                metadata: $metadata,
            );
        }
    }

    class_alias(MediaItemStubForHistory::class, \Phlix\Media\Library\MediaItem::class);
}
