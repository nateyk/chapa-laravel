<?php

declare(strict_types=1);

namespace Nateyk\Chapa\Exceptions;

final class ApiException extends ChapaException
{
    public function __construct(
        public readonly int $statusCode,
        public readonly ?string $errorCode,
        string $message,
    ) {
        parent::__construct($message, $statusCode);
    }
}
