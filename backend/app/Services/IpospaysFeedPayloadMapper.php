<?php

namespace App\Services;

use App\Data\MappedIpospaysFeedTransaction;
use App\Exceptions\IpospaysProviderProfileNotFinalizedException;

class IpospaysFeedPayloadMapper
{
    /**
     * This boundary intentionally has no provider field mapping until a signed
     * test vector and the financial/timestamp semantics are confirmed.
     *
     * @param  array<string, mixed>  $payload
     */
    public function map(array $payload): MappedIpospaysFeedTransaction
    {
        $profile = trim((string) config('ipospays.feed.mapping_profile'));

        throw new IpospaysProviderProfileNotFinalizedException(
            "No finalized iPOSpays FEED mapping profile is registered for '{$profile}'.",
        );
    }
}
