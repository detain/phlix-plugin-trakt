<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins\Scrobbler\Trakt;

use Phlix\Plugins\Scrobbler\Trakt\RemoteText;
use PHPUnit\Framework\TestCase;

/**
 * Covers the {@see RemoteText} boundary (PG-144): short remote text is
 * byte-identical through bound(), long remote text keeps a readable
 * prefix plus the marker, and the cut never splits a UTF-8 codepoint.
 */
final class RemoteTextTest extends TestCase
{
    public function testEmptyTextPassesThroughUntouched(): void
    {
        $this->assertSame('', RemoteText::bound(''));
    }

    public function testShortTextPassesThroughUntouched(): void
    {
        $this->assertSame('invalid_grant', RemoteText::bound('invalid_grant'));
    }

    public function testTextAtTheExactCapPassesThroughUntouched(): void
    {
        $text = str_repeat('x', RemoteText::MAX_MESSAGE_CHARS);

        $this->assertSame($text, RemoteText::bound($text));
    }

    public function testLongTextIsCutToCapPlusMarker(): void
    {
        $text = str_repeat('x', RemoteText::MAX_MESSAGE_CHARS + 1);

        $bounded = RemoteText::bound($text);

        $this->assertSame(str_repeat('x', RemoteText::MAX_MESSAGE_CHARS) . RemoteText::MARKER, $bounded);
    }

    public function testMultiByteCutLandsOnACodepointBoundary(): void
    {
        // 'é' is two UTF-8 bytes; a byte-wise cut would strand half of it.
        $text = str_repeat('é', RemoteText::MAX_MESSAGE_CHARS * 2);

        $bounded = RemoteText::bound($text);

        $this->assertTrue(mb_check_encoding($bounded, 'UTF-8'), 'the bounded message must be valid UTF-8');
        $expectedLength = RemoteText::MAX_MESSAGE_CHARS + mb_strlen(RemoteText::MARKER, 'UTF-8');
        $this->assertSame($expectedLength, mb_strlen($bounded, 'UTF-8'));
        $this->assertStringStartsWith(str_repeat('é', 64), $bounded);
    }
}
