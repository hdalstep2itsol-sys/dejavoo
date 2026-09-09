<?php

namespace App\Http\Requests\Admin;

use App\Enums\TrailerLoadStatus;
use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TrailerLoadReportRequest extends FormRequest
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
            'status' => ['nullable', Rule::in(TrailerLoadStatus::values())],
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'driver_id' => [
                'nullable',
                'integer',
                Rule::exists('users', 'id')->where('role', UserRole::Driver->value),
            ],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
