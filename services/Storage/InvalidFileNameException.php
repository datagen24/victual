<?php

namespace Victual\Services\Storage;

/**
 * Thrown by a storage backend when a caller supplies a name that is not a valid single
 * file name - one carrying a directory separator or a traversal segment.
 *
 * Its own class rather than a plain \Exception so that a caller which does reach the
 * backend with an unchecked name (services/StockService.php's barcode-picture path is
 * the one that does) fails the same way regardless of which backend is configured,
 * and so the files API - which already refuses these before either backend is reached -
 * can still answer 400 if a name ever got this far. Issue #243.
 */
class InvalidFileNameException extends \Exception
{
}
