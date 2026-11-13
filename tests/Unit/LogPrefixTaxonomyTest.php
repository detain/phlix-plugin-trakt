<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins\Scrobbler\Trakt;

use PHPUnit\Framework\TestCase;

/**
 * TRK-S19: every logger call in src/ starts its message with one of the four
 * allowed 'Trakt <component>:' prefixes.
 */
final class LogPrefixTaxonomyTest extends TestCase
{
    private const ALLOWED = ['Trakt API:', 'Trakt history sync:', 'Trakt OAuth state store:', 'Trakt plugin:'];

    public function testEveryLoggerMessageCarriesAnAllowedPrefix(): void
    {
        $src = dirname(__DIR__, 2) . '/src';
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS));
        $seen = 0;
        $bad = [];
        foreach ($it as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $code = (string) file_get_contents($file->getPathname());
            $n = preg_match_all(
                '/logger\s*->\s*(?:debug|info|notice|warning|error|critical|alert|emergency|log)\(\s*([\'"])(.{0,80})/s',
                $code,
                $m
            );
            foreach ($m[2] as $msg) {
                $seen++;
                $ok = false;
                foreach (self::ALLOWED as $prefix) {
                    if (str_starts_with($msg, $prefix)) {
                        $ok = true;
                    }
                }
                if (!$ok) {
                    $bad[] = $file->getFilename() . ': ' . substr($msg, 0, 40);
                }
            }
        }
        $this->assertGreaterThan(40, $seen, 'scan found the logger calls');
        $this->assertSame([], $bad, 'logger messages must use an allowed Trakt prefix');
    }
}
