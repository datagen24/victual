<?php

namespace Victual\Services\Storage;

/**
 * Thrown by a storage backend when a caller supplies a name that is not a valid single
 * file name - one carrying a directory separator or a traversal segment. Its own class,
 * rather than a plain \Exception, so BaseApiController can map it to 400 regardless of
 * which backend is configured.
 */
class InvalidFileNameException extends \Exception
{
}
