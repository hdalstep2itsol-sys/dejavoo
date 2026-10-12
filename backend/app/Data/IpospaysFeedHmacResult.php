<?php

namespace App\Data;

use App\Enums\IpospaysFeedHmacStatus;

final readonly class IpospaysFeedHmacResult
{
    public function __construct(
        public IpospaysFeedHmacStatus $status,
        public ?string $profileName = null,
        public ?int $canonicalLength = null,
        public ?string $canonicalSha256 = null,
        public ?string $expectedSignatureFingerprint = null,
        public ?string $receivedSignatureFingerprint = null,
        public ?bool $signaturesMatched = null,
    ) {}

    public function verified(): bool
    {
        return $this->status === IpospaysFeedHmacStatus::Verified;
    }
}
