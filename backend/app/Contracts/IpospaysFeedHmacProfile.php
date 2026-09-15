<?php

namespace App\Contracts;

interface IpospaysFeedHmacProfile
{
    public function name(): string;

    /**
     * Compute the expected lowercase hexadecimal signature using a confirmed
     * provider canonicalization profile.
     *
     * @param  array<string, mixed>  $payload
     */
    public function expectedSignature(array $payload, string $secret): string;
}
