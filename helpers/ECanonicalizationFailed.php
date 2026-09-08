<?php

namespace Victual\Helpers;

/**
 * A document that has no RFC 8785 canonical form.
 *
 * Its own class rather than a plain \Exception because the label API answers it as a
 * refusal with a code rather than as an internal error: a document carrying NaN, an
 * unrepresentable integer or invalid UTF-8 is a bad request, and a caller has to be able to
 * tell that from the server having failed.
 */
class ECanonicalizationFailed extends \Exception
{
}
