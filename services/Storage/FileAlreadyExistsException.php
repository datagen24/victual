<?php

namespace Victual\Services\Storage;

/**
 * Thrown by a storage backend's Create() when a file of that name already exists.
 *
 * A subclass of the plain \Exception Create() has always thrown, with the same message,
 * so existing callers (the files API answers any Create failure with 400) behave exactly
 * as before; a caller that wants to treat "already there" differently from "the write
 * failed" - the external barcode lookup's picture step - can catch it by class.
 */
class FileAlreadyExistsException extends \Exception
{
}
