<?php

namespace App\Http\Requests\Admin;

use App\Enums\LocationRouteType;
use App\Http\Requests\Admin\Concerns\ValidatesLocationRoute;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreLocationRequest extends FormRequest
{
    use ValidatesLocationRoute;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'unit_price' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'haul_threshold' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'route_type' => ['required', Rule::enum(LocationRouteType::class)],
            'dedicated_driver_id' => ['nullable', 'integer'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [
            fn (Validator $validator) => $this->validateRouteAssignment(
                $validator,
                $this->input('route_type'),
                $this->input('dedicated_driver_id'),
            ),
        ];
    }
}
