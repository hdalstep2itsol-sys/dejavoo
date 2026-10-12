<?php

namespace App\Contracts;

interface IpospaysFeedHmacProfile
{
    public function name(): string;

    /**
     * Build the exact provider canonical string. Callers must never log the
     * returned value; only non-reversible metadata such as its length/hash is
     * safe for diagnostics.
     *
     * @param  array<string, mixed>  $payload
     */
    public function canonicalize(array $payload): string;

    /**
     * Compute the expected lowercase hexadecimal signature using a confirmed
     * provider canonicalization profile.
     *
     * @param  array<string, mixed>  $payload
     */
    public function expectedSignature(array $payload, string $secret): string;
}
