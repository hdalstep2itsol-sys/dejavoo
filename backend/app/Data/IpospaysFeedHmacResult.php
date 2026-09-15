<?php

namespace App\Data;

use App\Enums\IpospaysFeedHmacStatus;

final readonly class IpospaysFeedHmacResult
{
    public function __construct(public IpospaysFeedHmacStatus $status) {}

    public function verified(): bool
    {
        return $this->status === IpospaysFeedHmacStatus::Verified;
    }
}
