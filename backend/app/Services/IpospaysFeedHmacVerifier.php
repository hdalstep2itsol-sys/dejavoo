<?php

namespace App\Services;

use App\Contracts\IpospaysFeedHmacProfile;
use App\Data\IpospaysFeedHmacResult;
use App\Enums\IpospaysFeedHmacStatus;
use Throwable;

class IpospaysFeedHmacVerifier
{
    /**
     * No profile is registered until Dejavoo supplies a confirmed canonical
     * contract and signed test vector.
     *
     * @param  array<string, IpospaysFeedHmacProfile>  $profiles
     */
    public function __construct(private readonly array $profiles = []) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function verify(array $payload): IpospaysFeedHmacResult
    {
        $receivedSignature = $payload['signature'] ?? null;
        if (! is_string($receivedSignature) || trim($receivedSignature) === '') {
            return new IpospaysFeedHmacResult(IpospaysFeedHmacStatus::SignatureMissing);
        }

        if (! config('ipospays.feed.enabled')) {
            return new IpospaysFeedHmacResult(IpospaysFeedHmacStatus::FeedDisabled);
        }

        $secret = (string) config('ipospays.feed.hmac_secret');
        if ($secret === '') {
            return new IpospaysFeedHmacResult(IpospaysFeedHmacStatus::SecretMissing);
        }

        $profileName = trim((string) config('ipospays.feed.hmac_profile'));
        $profile = $this->profiles[$profileName] ?? null;
        if (! $profile instanceof IpospaysFeedHmacProfile) {
            return new IpospaysFeedHmacResult(IpospaysFeedHmacStatus::ProfileUnfinalized);
        }

        try {
            $expectedSignature = $profile->expectedSignature($payload, $secret);
        } catch (Throwable) {
            return new IpospaysFeedHmacResult(IpospaysFeedHmacStatus::VerificationFailed);
        }

        if ($expectedSignature === '' || ! hash_equals($expectedSignature, trim($receivedSignature))) {
            return new IpospaysFeedHmacResult(IpospaysFeedHmacStatus::InvalidSignature);
        }

        return new IpospaysFeedHmacResult(IpospaysFeedHmacStatus::Verified);
    }
}
