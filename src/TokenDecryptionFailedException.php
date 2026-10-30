<?php

/**
 * Phlix media server component: Trakt.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Plugins\Scrobbler\Trakt;

/**
 * Raised when a stored value carries the cipher's own version marker ("v1:")
 * but cannot be decrypted — wrong key (rotation) or a corrupted/tampered
 * envelope.
 *
 * This is deliberately NOT raised for values WITHOUT the marker: those are
 * legacy plaintext written before encryption was enabled and decrypt() passes
 * them through unchanged by design (M-TRAKT-20). The distinction matters:
 * an unreadable envelope of OUR format is a hard custody failure, and the
 * caller must treat the account as unconfigured instead of riding the
 * literal "v1:..." string as if it were a token.
 *
 * @package Phlix\Plugins\Scrobbler\Trakt
 * @since 1.5.0
 */
final class TokenDecryptionFailedException extends \RuntimeException
{
}
