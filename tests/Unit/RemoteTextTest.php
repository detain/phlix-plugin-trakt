<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins\Scrobbler\Trakt;

use Phlix\Plugins\Scrobbler\Trakt\RemoteText;
use PHPUnit\Framework\TestCase;

/**
 * Covers the {@see RemoteText} boundary (PG-144): short remote text is
 * byte-identical through bound(), long remote text keeps a readable
 * prefix plus the marker, and the cut never splits a UTF-8 codepoint.
 * TRK-5: ASCII control characters (< 0x20 and 0x7f) are stripped at the
 * same boundary, before the length is measured.
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

    public function testCarriageReturnAndLineFeedAreStripped(): void
    {
        // A remote CR/LF would otherwise forge a second log line.
        $this->assertSame(
            'invalid_grantFAKE LOG LINE',
            RemoteText::bound("invalid_grant\r\nFAKE LOG LINE")
        );
    }

    public function testNulIsStripped(): void
    {
        $this->assertSame('beforeafter', RemoteText::bound("before\0after"));
    }

    public function testAnsiEscapeIntroducerAndDelAreStripped(): void
    {
        // ESC (0x1b) introduces terminal repaint sequences; DEL is 0x7f.
        $this->assertSame('[31mred[0m', RemoteText::bound("\x1b[31mred\x1b[0m\x7f"));
    }

    public function testEveryControlByteIsStrippedAndPrintableAsciiIsKept(): void
    {
        $controls = implode('', array_map('chr', [...range(0x00, 0x1F), 0x7F]));
        $printable = implode('', array_map('chr', range(0x20, 0x7E)));

        $this->assertSame('', RemoteText::bound($controls));
        $this->assertSame($printable, RemoteText::bound($printable));
    }

    public function testMultiByteTextSurvivesTheStripByteIdentical(): void
    {
        // Continuation bytes are >= 0x80, so the byte-wise class never touches them.
        $text = "Ungültiger Schlüssel — 無効なトークン";

        $this->assertSame($text, RemoteText::bound($text));
    }

    public function testControlCharactersDoNotCountTowardTheCap(): void
    {
        $printable = str_repeat('x', RemoteText::MAX_MESSAGE_CHARS);
        $text = str_repeat("\n", 64) . $printable;

        $this->assertSame($printable, RemoteText::bound($text));
    }

    public function testLongTextIsStrippedThenCut(): void
    {
        $text = str_repeat("x\r\n", RemoteText::MAX_MESSAGE_CHARS + 1);

        $this->assertSame(
            str_repeat('x', RemoteText::MAX_MESSAGE_CHARS) . RemoteText::MARKER,
            RemoteText::bound($text)
        );
    }
}
