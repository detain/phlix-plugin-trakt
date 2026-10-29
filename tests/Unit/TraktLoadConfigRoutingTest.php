<?php

/**
 * Phlix media server component: Trakt loadConfig routing pins.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins\Scrobbler\Trakt;

use Phlix\Plugins\Scrobbler\Trakt\TraktOperatorConfig;
use Phlix\Plugins\Scrobbler\Trakt\TraktPlugin;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Drift-prevention pins for TraktPlugin::loadConfig() → TraktOperatorConfig
 * (review trakt__docmaint M2).
 *
 * The class header used to narrate a completed "both callers route through
 * here" fix that this repo's production code never had: loadConfig() was a
 * raw include. These tests make the routing real and lock it:
 *
 *  1. the plugin's read and the shared class resolve the SAME normalized
 *     shape for any fixture file (the family idiom for "cannot drift again");
 *  2. the routing is behavior-neutral — an array-returning file resolves to
 *     exactly its own values (no overlay keys injected or dropped), a
 *     missing file and the untampered default path resolve to [];
 *  3. a non-array file normalizes to [] (teeth on the shared is_array
 *     guard; the raw include used to hand the caller whatever the file
 *     returned).
 */
final class TraktLoadConfigRoutingTest extends TestCase
{
    /**
     * @param string|null $configFile Test seam path; null exercises the
     *                                production default-path behavior.
     *
     * @return array<string, mixed>
     */
    private function invokeLoadConfig(?string $configFile): array
    {
        $plugin = new TraktPlugin();
        $method = new ReflectionMethod($plugin, 'loadConfig');
        $method->setAccessible(true);
        $result = $configFile === null
            ? $method->invoke($plugin)
            : $method->invoke($plugin, $configFile);

        $this->assertIsArray($result);

        return $result;
    }

    /**
     * @param string $phpFileContents Full PHP source for the fixture.
     */
    private function writeTempConfig(string $phpFileContents): string
    {
        $tmpFile = sys_get_temp_dir() . '/trakt_routing_test_' . uniqid() . '.php';
        file_put_contents($tmpFile, $phpFileContents);

        return $tmpFile;
    }

    public function testFixtureFileResolvesToExactlyItsOwnValues(): void
    {
        $tmpFile = $this->writeTempConfig(
            '<?php return [\'client_id\' => \'file-id\', \'client_secret\' => \'file-secret\','
            . ' \'redirect_uri\' => \'https://example.test/cb\', \'token_encryption_key\' => \'key-abc\'];'
        );

        try {
            $result = $this->invokeLoadConfig($tmpFile);

            // Behavior-neutrality pin: the old raw include returned this
            // exact array; the routed read must not add, drop, or rewrite
            // any key (no overlay handle exists in plugin context, so no
            // server_settings values can leak in).
            $this->assertSame([
                'client_id' => 'file-id',
                'client_secret' => 'file-secret',
                'redirect_uri' => 'https://example.test/cb',
                'token_encryption_key' => 'key-abc',
            ], $result);
        } finally {
            unlink($tmpFile);
        }
    }

    public function testPluginReadAndOperatorClassNeverDrift(): void
    {
        $tmpFile = $this->writeTempConfig(
            '<?php return [\'client_id\' => \'file-id\', \'extra\' => 1];'
        );

        try {
            // The drift law: whatever loadConfig() resolves MUST equal the
            // shared class's resolution of the same file. Reverting the
            // routing to a raw include reddens this against the non-array
            // sibling test; removing the is_array guard inside the shared
            // class reddens the sibling and this pair together.
            $this->assertSame(
                TraktOperatorConfig::load($tmpFile, null),
                $this->invokeLoadConfig($tmpFile)
            );
        } finally {
            unlink($tmpFile);
        }
    }

    public function testNonArrayConfigFileNormalizesToEmptyArray(): void
    {
        $tmpFile = $this->writeTempConfig('<?php return \'not-an-array\';');

        try {
            $this->assertSame([], $this->invokeLoadConfig($tmpFile));
        } finally {
            unlink($tmpFile);
        }
    }

    public function testMissingConfigFileResolvesToEmptyArray(): void
    {
        $this->assertSame([], $this->invokeLoadConfig('/nonexistent/trakt_config_test.php'));
    }

    public function testProductionDefaultPathResolvesToEmptyArrayAtThisVenue(): void
    {
        // Untampered default-path equality pin: both pre- and post-routing,
        // dirname(__DIR__, 5) . '/config/scrobblers/trakt.php' does not exist
        // in this checkout or in CI, so the production read resolves to [].
        // (The path depth itself is a reported host-defect: in a vendored
        // install it resolves ABOVE the server root — see the loadConfig()
        // method docblock; correcting it changes credential resolution and
        // is deliberately NOT this lane's cure.)
        $this->assertSame([], $this->invokeLoadConfig(null));
    }
}
