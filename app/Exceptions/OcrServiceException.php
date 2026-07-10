<?php

namespace App\Exceptions;

use Exception;

class OcrServiceException extends Exception
{
    public function __construct(
        string $message,
        public readonly ?int $httpStatus = null,
        public readonly ?array $responseBody = null,
    ) {
        parent::__construct($message);
    }
}
