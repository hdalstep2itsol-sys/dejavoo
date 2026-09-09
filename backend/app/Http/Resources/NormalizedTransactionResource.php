<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NormalizedTransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'location_id' => $this->location_id,
            'trailer_load_id' => $this->trailer_load_id,
            'transaction_type' => $this->transaction_type->value,
            'business_amount' => $this->business_amount,
            'unit_price_snapshot' => $this->unit_price_snapshot,
            'unit_delta' => $this->unit_delta,
            'occurred_at' => $this->occurred_at?->toIso8601String(),
            'source' => $this->source,
        ];
    }
}
