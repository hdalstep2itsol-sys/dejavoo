<?php

namespace App\Http\Requests\Admin;

use App\Enums\TrailerLoadStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LoadHistoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'location_id' => ['nullable', 'integer', 'exists:locations,id'],
            'status' => [
                'nullable',
                Rule::in([
                    TrailerLoadStatus::PendingWarehouseCount->value,
                    TrailerLoadStatus::Completed->value,
                ]),
            ],
        ];
    }
}
