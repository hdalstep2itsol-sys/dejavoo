<?php

namespace App\Data;

use App\Enums\IpospaysFeedBasicAuthStatus;

final readonly class IpospaysFeedBasicAuthResult
{
    public function __construct(
        public IpospaysFeedBasicAuthStatus $status,
        public bool $headerPresent,
        public bool $usernamePresent,
    ) {}

    public function verified(): bool
    {
        return $this->status === IpospaysFeedBasicAuthStatus::Verified;
    }
}
