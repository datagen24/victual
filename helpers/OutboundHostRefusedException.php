<?php

namespace Victual\Helpers;

/**
 * Thrown by OutboundHostPolicy::AssertAllowed() when a URL's scheme or resolved host is not
 * allowed to be fetched - see that class for the policy it enforces.
 */
class OutboundHostRefusedException extends \Exception
{
}
