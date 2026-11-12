<?php

declare(strict_types=1);

namespace Phlix\Plugins\Scrobbler\Trakt;

/**
 * Bounds untrusted remote text before it is echoed into exception messages.
 *
 * Every string an error path lifts out of a Trakt response body — an
 * `error`, a `message`, an OAuth `error_description` — is remote text:
 * the upstream API, or anything masquerading as it on the wire, chooses
 * its length and its content. Copying it verbatim into a
 * {@see TraktApiException} lets a hostile or merely broken endpoint
 * inflate log lines and admin-UI toasts without limit (review
 * trakt__secaudit info #7 / PG-144), so the boundary cuts long values
 * down to a readable prefix plus an honest marker instead.
 *
 * The same boundary also strips ASCII control characters (every byte
 * below 0x20 — CR, LF, NUL, TAB, ESC, … — plus DEL 0x7f) before the
 * length is measured (TRK-5): a remote CR/LF would otherwise forge a
 * second log line, and an ESC sequence would repaint the operator's
 * terminal when the log is tailed.
 *
 * The cut is codepoint-safe for UTF-8: a truncated message is still
 * rendered to humans, and a broken codepoint would ride into log files
 * as replacement characters that obscure the next grep.
 *
 * @package Phlix\Plugins\Scrobbler\Trakt
 */
final class RemoteText
{
    /** Longest kept stretch of remote text, in characters (not bytes). */
    public const MAX_MESSAGE_CHARS = 512;

    /** Appended when text was cut, so consumers never read a bound value as complete. */
    public const MARKER = ' [truncated]';

    /**
     * Strip ASCII control characters from $text, then return it when it
     * fits, or its first {@see MAX_MESSAGE_CHARS} characters plus the
     * truncation marker.
     *
     * Pure: the same input always yields the same output, and short
     * printable text — the normal case — passes through byte-identical so
     * existing verbatim-message law pins stay green.
     *
     * The strip runs byte-wise (no `u` modifier) on purpose: every byte of
     * a multi-byte UTF-8 sequence is >= 0x80, so the class can never split
     * a codepoint, and malformed UTF-8 is still sanitized instead of making
     * the whole match fail.
     */
    public static function bound(string $text): string
    {
        // A single-byte character class cannot backtrack or hit a PCRE
        // limit, so null is unreachable; '' is the fail-closed fallback
        // that keeps the type total without echoing unsanitized text.
        $text = preg_replace('/[\x00-\x1F\x7F]/', '', $text) ?? '';

        if (mb_strlen($text, 'UTF-8') <= self::MAX_MESSAGE_CHARS) {
            return $text;
        }

        return mb_substr($text, 0, self::MAX_MESSAGE_CHARS, 'UTF-8') . self::MARKER;
    }
}
