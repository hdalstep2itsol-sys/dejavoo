<?php

namespace App\Http\Controllers\Api\Webhooks;

use App\Exceptions\IpospaysProviderProfileNotFinalizedException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Webhooks\IpospaysFeedRequest;
use App\Services\IpospaysFeedAcknowledgement;
use App\Services\IpospaysFeedPayloadMapper;
use App\Services\IpospaysFeedProcessor;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

class IpospaysFeedController extends Controller
{
    public function __invoke(
        IpospaysFeedRequest $request,
        IpospaysFeedPayloadMapper $mapper,
        IpospaysFeedProcessor $processor,
        IpospaysFeedAcknowledgement $acknowledgement,
    ): JsonResponse {
        try {
            $mappedTransaction = $mapper->map($request->feedPayload());
            $result = $processor->process($mappedTransaction);

            return $acknowledgement->processingResult($result->status);
        } catch (IpospaysProviderProfileNotFinalizedException) {
            $event = $processor->recordPendingProviderMapping();
            Log::warning('iPOSpays FEED payload mapping rejected.', [
                'code' => 'provider_mapping_unfinalized',
                'feed_event_id' => $event->id,
            ]);

            return $acknowledgement->pendingProviderMapping();
        } catch (Throwable) {
            Log::error('iPOSpays FEED processing failed.', [
                'code' => 'temporary_processing_failure',
            ]);

            return $acknowledgement->transientFailure();
        }
    }
}
