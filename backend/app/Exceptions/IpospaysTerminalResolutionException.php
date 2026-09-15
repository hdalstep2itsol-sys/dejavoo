<?php

namespace App\Exceptions;

use App\Enums\IpospaysFeedEventStatus;
use RuntimeException;

class IpospaysTerminalResolutionException extends RuntimeException
{
    public function __construct(
        public readonly IpospaysFeedEventStatus $status,
        public readonly string $errorCode,
    ) {
        parent::__construct($errorCode);
    }
}
