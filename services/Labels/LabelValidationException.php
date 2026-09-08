<?php

namespace Victual\Services\Labels;

class LabelValidationException extends \RuntimeException
{
    public function __construct(public readonly string $field, public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }
}
