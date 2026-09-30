<?php

namespace App\Services;

use App\Data\MappedIpospaysFeedTransaction;
use App\Data\MappedIpospaysFeedUnsupportedEvent;
use App\Exceptions\IpospaysProviderProfileNotFinalizedException;

class IpospaysFeedPayloadMapper
{
    public function __construct(private readonly DejavooFeedV1PayloadMapper $dejavooFeedV1) {}

    /** @param array<string, mixed> $payload */
    public function map(array $payload): MappedIpospaysFeedTransaction|MappedIpospaysFeedUnsupportedEvent
    {
        $profile = trim((string) config('ipospays.feed.mapping_profile'));

        if ($profile === DejavooFeedV1PayloadMapper::NAME) {
            return $this->dejavooFeedV1->map($payload);
        }

        throw new IpospaysProviderProfileNotFinalizedException(
            "No finalized iPOSpays FEED mapping profile is registered for '{$profile}'.",
        );
    }
}
