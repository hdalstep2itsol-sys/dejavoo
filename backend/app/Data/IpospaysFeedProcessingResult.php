<?php

namespace App\Data;

use App\Enums\IpospaysFeedEventStatus;
use App\Models\IpospaysFeedEvent;
use App\Models\NormalizedTransaction;

final readonly class IpospaysFeedProcessingResult
{
    public function __construct(
        public IpospaysFeedEventStatus $status,
        public IpospaysFeedEvent $event,
        public ?NormalizedTransaction $normalizedTransaction = null,
    ) {}
}
