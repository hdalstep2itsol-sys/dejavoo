<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreTrailerLoadAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'unit_delta' => ['required', 'numeric', 'decimal:0,8', 'not_in:0'],
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->reason)) {
            $this->merge(['reason' => trim($this->reason)]);
        }
    }
}
