<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins\Scrobbler\Trakt;

use Phlix\Plugins\Scrobbler\Trakt\TraktPlugin;
use Phlix\Plugins\Scrobbler\Trakt\TraktSettings;
use Phlix\Plugins\Scrobbler\Trakt\TraktSettingsRepository;
use Phlix\Plugins\Scrobbler\Trakt\TokenCipher;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Tests for TraktPlugin private method branches via onEnable.
 *
 * These tests exercise the private resolveSettingsRepository and resolveTokenCipher
 * methods indirectly through onEnable to improve code coverage.
 */
final class TraktPluginOnEnableCoverageTest extends TestCase
{
    public function testOnEnableWithDbConnectionThrows(): void
    {
        $settings = new TraktSettings(
            accessToken: 'test-access',
            refreshToken: 'test-refresh',
            username: 'testuser',
            syncEnabled: true,
        );

        $plugin = new TraktPlugin($settings, new NullLogger());

        // Create a container where Connection get() throws
        $container = new ThrowingServiceContainer(\Workerman\MySQL\Connection::class);
        $container->addService(\Phlix\Media\Library\ItemRepository::class, new FakeItemRepositoryForCoverageTest());
        $container->addService(\Phlix\Auth\WatchHistory::class, null);

        // Should not throw - exception is caught internally
        $plugin->onEnable($container);

        // db should be null due to the exception being caught
        $reflection = new \ReflectionClass($plugin);
        $prop = $reflection->getProperty('db');
        $prop->setAccessible(true);
        $this->assertNull($prop->getValue($plugin));
    }

    public function testOnEnableWithTokenCipherInContainerNotInstance(): void
    {
        $settings = new TraktSettings(
            accessToken: 'test-access',
            refreshToken: 'test-refresh',
            username: 'testuser',
            syncEnabled: true,
        );

        $plugin = new TraktPlugin($settings, new NullLogger());

        // Container has TokenCipher but it's the wrong type (stdClass)
        $container = new StubContainerWithAdd([
            \Phlix\Media\Library\ItemRepository::class => new FakeItemRepositoryForCoverageTest(),
            \Phlix\Auth\WatchHistory::class => null,
            \Workerman\MySQL\Connection::class => new FakeDbConnection(),
            TokenCipher::class => new \stdClass(), // Not a TokenCipher!
        ]);

        // Should not throw
        $plugin->onEnable($container);

        // tokenCipher should still be resolved via fallback (fromConfig)
        $reflection = new \ReflectionClass($plugin);
        $prop = $reflection->getProperty('tokenCipher');
        $prop->setAccessible(true);
        $cipher = $prop->getValue($plugin);
        // The fallback is SodiumTokenCipher::fromConfig(null) which returns null
        // because no encryption key is configured
        $this->assertNull($cipher);
    }

    public function testOnEnableWithTokenCipherThrows(): void
    {
        $settings = new TraktSettings(
            accessToken: 'test-access',
            refreshToken: 'test-refresh',
            username: 'testuser',
            syncEnabled: true,
        );

        $plugin = new TraktPlugin($settings, new NullLogger());

        // Create container that throws when getting TokenCipher
        $container = new ThrowingServiceContainer(TokenCipher::class);
        $container->addService(\Phlix\Media\Library\ItemRepository::class, new FakeItemRepositoryForCoverageTest());
        $container->addService(\Phlix\Auth\WatchHistory::class, null);
        $container->addService(\Workerman\MySQL\Connection::class, new FakeDbConnection());

        // Should not throw - exception is caught internally
        $plugin->onEnable($container);

        // tokenCipher should fallback to SodiumTokenCipher::fromConfig(null) which is null
        $reflection = new \ReflectionClass($plugin);
        $prop = $reflection->getProperty('tokenCipher');
        $prop->setAccessible(true);
        $this->assertNull($prop->getValue($plugin));
    }

    public function testOnEnableWithSettingsRepositoryNotInstance(): void
    {
        $settings = new TraktSettings(
            accessToken: 'test-access',
            refreshToken: 'test-refresh',
            username: 'testuser',
            syncEnabled: true,
        );

        $plugin = new TraktPlugin($settings, new NullLogger());

        // Container has TraktSettingsRepository but it's the wrong type
        $container = new StubContainerWithAdd([
            \Phlix\Media\Library\ItemRepository::class => new FakeItemRepositoryForCoverageTest(),
            \Phlix\Auth\WatchHistory::class => null,
            \Workerman\MySQL\Connection::class => new FakeDbConnection(),
            TraktSettingsRepository::class => new \stdClass(), // Wrong type!
        ]);

        // Should not throw
        $plugin->onEnable($container);

        // settingsRepository should be null because stdClass is not TraktSettingsRepository
        $reflection = new \ReflectionClass($plugin);
        $prop = $reflection->getProperty('settingsRepository');
        $prop->setAccessible(true);
        $this->assertNull($prop->getValue($plugin));
    }
}

/**
 * Container that throws when a specific service is requested.
 */
final class ThrowingServiceContainer implements ContainerInterface
{
    private string $throwingService;
    /** @var array<string, mixed> */
    private array $services;

    public function __construct(string $throwingService)
    {
        $this->throwingService = $throwingService;
        $this->services = [];
    }

    public function addService(string $id, mixed $service): void
    {
        $this->services[$id] = $service;
    }

    public function get(string $id): mixed
    {
        if ($id === $this->throwingService) {
            throw new \RuntimeException('Container service unavailable');
        }
        return $this->services[$id] ?? null;
    }

    public function has(string $id): bool
    {
        return $id === $this->throwingService || array_key_exists($id, $this->services);
    }
}

/**
 * Stub container with add method for building up services.
 */
final class StubContainerWithAdd implements ContainerInterface
{
    /** @var array<string, mixed> */
    public array $services;

    public function __construct(array $services = [])
    {
        $this->services = $services;
    }

    public function get(string $id): mixed
    {
        return $this->services[$id] ?? null;
    }

    public function has(string $id): bool
    {
        return array_key_exists($id, $this->services);
    }
}

/**
 * Fake ItemRepository for testing.
 */
final class FakeItemRepositoryForCoverageTest
{
    public function findById(string $id): ?array
    {
        return [
            'id' => $id,
            'name' => 'Test Movie',
            'type' => 'movie',
            'path' => '/movies/test.mkv',
            'metadata' => [
                'trakt_id' => 1,
                'imdb_id' => 'tt0000001',
                'tmdb_id' => 42,
                'duration_seconds' => 7200,
            ],
        ];
    }
}

/**
 * Fake DB connection for testing.
 */
final class FakeDbConnection
{
    public function query(string $sql, array $params = []): array { return []; }
    public function real_escapeString(string $s): string { return $s; }
}
