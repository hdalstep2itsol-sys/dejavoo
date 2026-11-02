<?php

namespace App\Services;

use App\Data\IpospaysFeedBasicAuthResult;
use App\Enums\IpospaysFeedBasicAuthStatus;
use Illuminate\Http\Request;

class IpospaysFeedBasicAuthVerifier
{
    public function verify(Request $request): IpospaysFeedBasicAuthResult
    {
        $headerPresent = $request->headers->has('Authorization');
        $authorization = $request->headers->get('Authorization');

        if (! is_string($authorization) || trim($authorization) === '') {
            return new IpospaysFeedBasicAuthResult(
                IpospaysFeedBasicAuthStatus::Missing,
                $headerPresent,
                false,
            );
        }

        if (preg_match('/^Basic[\x20\x09]+([A-Za-z0-9+\/=]+)$/iD', $authorization, $matches) !== 1) {
            return new IpospaysFeedBasicAuthResult(
                IpospaysFeedBasicAuthStatus::Malformed,
                true,
                false,
            );
        }

        $decoded = base64_decode($matches[1], true);
        if (! is_string($decoded) || ! str_contains($decoded, ':')) {
            return new IpospaysFeedBasicAuthResult(
                IpospaysFeedBasicAuthStatus::Malformed,
                true,
                false,
            );
        }

        [$providedUsername, $providedPassword] = explode(':', $decoded, 2);
        $usernamePresent = $providedUsername !== '';

        if (! config('ipospays.feed.enabled')) {
            return new IpospaysFeedBasicAuthResult(
                IpospaysFeedBasicAuthStatus::FeedDisabled,
                true,
                $usernamePresent,
            );
        }

        $expectedUsername = (string) config('ipospays.feed.basic_username');
        $expectedPassword = (string) config('ipospays.feed.basic_password');
        if ($expectedUsername === '' || $expectedPassword === '') {
            return new IpospaysFeedBasicAuthResult(
                IpospaysFeedBasicAuthStatus::CredentialsMissing,
                true,
                $usernamePresent,
            );
        }

        $usernameMatches = hash_equals($expectedUsername, $providedUsername);
        $passwordMatches = hash_equals($expectedPassword, $providedPassword);

        return new IpospaysFeedBasicAuthResult(
            ($usernameMatches & $passwordMatches)
                ? IpospaysFeedBasicAuthStatus::Verified
                : IpospaysFeedBasicAuthStatus::Invalid,
            true,
            $usernamePresent,
        );
    }
}
