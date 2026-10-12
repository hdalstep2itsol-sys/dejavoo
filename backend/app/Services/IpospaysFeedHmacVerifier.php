<?php

namespace App\Services;

use App\Contracts\IpospaysFeedHmacProfile;
use App\Data\IpospaysFeedHmacResult;
use App\Enums\IpospaysFeedHmacStatus;
use Throwable;

class IpospaysFeedHmacVerifier
{
    /**
     * @param  array<string, IpospaysFeedHmacProfile>  $profiles
     */
    public function __construct(private readonly array $profiles = []) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function verify(array $payload): IpospaysFeedHmacResult
    {
        $profileName = trim((string) config('ipospays.feed.hmac_profile'));
        $receivedSignature = $payload['signature'] ?? null;
        if (! is_string($receivedSignature) || trim($receivedSignature) === '') {
            return new IpospaysFeedHmacResult(
                IpospaysFeedHmacStatus::SignatureMissing,
                profileName: $profileName,
            );
        }

        $receivedSignature = trim($receivedSignature);
        $receivedFingerprint = $this->fingerprint($receivedSignature);

        if (! config('ipospays.feed.enabled')) {
            return new IpospaysFeedHmacResult(
                IpospaysFeedHmacStatus::FeedDisabled,
                profileName: $profileName,
                receivedSignatureFingerprint: $receivedFingerprint,
            );
        }

        $secret = (string) config('ipospays.feed.hmac_secret');
        if ($secret === '') {
            return new IpospaysFeedHmacResult(
                IpospaysFeedHmacStatus::SecretMissing,
                profileName: $profileName,
                receivedSignatureFingerprint: $receivedFingerprint,
            );
        }

        $profile = $this->profiles[$profileName] ?? null;
        if (! $profile instanceof IpospaysFeedHmacProfile) {
            return new IpospaysFeedHmacResult(
                IpospaysFeedHmacStatus::ProfileUnfinalized,
                profileName: $profileName,
                receivedSignatureFingerprint: $receivedFingerprint,
            );
        }

        try {
            $canonical = $profile->canonicalize($payload);
            $expectedSignature = $profile->expectedSignature($payload, $secret);
        } catch (Throwable) {
            return new IpospaysFeedHmacResult(
                IpospaysFeedHmacStatus::VerificationFailed,
                profileName: $profileName,
                receivedSignatureFingerprint: $receivedFingerprint,
            );
        }

        $canonicalLength = strlen($canonical);
        $canonicalSha256 = hash('sha256', $canonical);
        $expectedFingerprint = $this->fingerprint($expectedSignature);
        $matches = $expectedSignature !== '' && hash_equals($expectedSignature, $receivedSignature);

        return new IpospaysFeedHmacResult(
            $matches
                ? IpospaysFeedHmacStatus::Verified
                : IpospaysFeedHmacStatus::InvalidSignature,
            profileName: $profileName,
            canonicalLength: $canonicalLength,
            canonicalSha256: $canonicalSha256,
            expectedSignatureFingerprint: $expectedFingerprint,
            receivedSignatureFingerprint: $receivedFingerprint,
            signaturesMatched: $matches,
        );
    }

    private function fingerprint(string $signature): string
    {
        return substr(hash('sha256', $signature), 0, 16);
    }
}
