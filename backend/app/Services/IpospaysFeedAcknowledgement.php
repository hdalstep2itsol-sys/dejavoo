<?php

namespace App\Services;

use App\Enums\IpospaysFeedEventStatus;
use App\Enums\IpospaysFeedHmacStatus;
use Illuminate\Http\JsonResponse;

class IpospaysFeedAcknowledgement
{
    public function malformedJson(): JsonResponse
    {
        return $this->response('rejected', 'malformed_json', 400);
    }

    public function payloadTooLarge(): JsonResponse
    {
        return $this->response('rejected', 'payload_too_large', 413);
    }

    public function hmacFailure(IpospaysFeedHmacStatus $status): JsonResponse
    {
        $httpStatus = match ($status) {
            IpospaysFeedHmacStatus::SignatureMissing,
            IpospaysFeedHmacStatus::InvalidSignature => 401,
            default => 503,
        };

        return $this->response('rejected', $status->value, $httpStatus);
    }

    public function pendingProviderMapping(): JsonResponse
    {
        return $this->response('rejected', 'provider_mapping_unfinalized', 503);
    }

    public function transientFailure(): JsonResponse
    {
        return $this->response('rejected', 'temporary_processing_failure', 503);
    }

    public function processingResult(IpospaysFeedEventStatus $status): JsonResponse
    {
        return match ($status) {
            IpospaysFeedEventStatus::Processed,
            IpospaysFeedEventStatus::Duplicate => $this->response('accepted', $status->value, 200),
            IpospaysFeedEventStatus::TerminalUnknown,
            IpospaysFeedEventStatus::TerminalInactive,
            IpospaysFeedEventStatus::LocationInactive,
            IpospaysFeedEventStatus::Conflict,
            IpospaysFeedEventStatus::Unsupported => $this->response('rejected', $status->value, 422),
            default => $this->transientFailure(),
        };
    }

    private function response(string $status, string $code, int $httpStatus): JsonResponse
    {
        return response()->json([
            'status' => $status,
            'code' => $code,
        ], $httpStatus);
    }
}
