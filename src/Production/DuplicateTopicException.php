<?php
/**
 * Thema ähnelt einem schon geschriebenen Thema.
 *
 * @package Lsec
 */

namespace Lsec\Production;

/**
 * Wird beim Start eines Entwurfs geworfen, wenn es zu einem sehr ähnlichen Thema schon einen Entwurf gibt.
 * Das Backend fragt dann „Trotzdem schreiben?“ (0.11.0); WP-CLI nennt --allow-duplicate.
 */
final class DuplicateTopicException extends \RuntimeException {}
