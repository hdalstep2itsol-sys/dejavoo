<?php

namespace App\Http\Requests\Admin;

use App\Enums\LocationRouteType;
use App\Http\Requests\Admin\Concerns\ValidatesLocationRoute;
use App\Models\Location;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateLocationRequest extends FormRequest
{
    use ValidatesLocationRoute;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'unit_price' => ['sometimes', 'required', 'numeric', 'gt:0', 'decimal:0,2'],
            'haul_threshold' => ['sometimes', 'required', 'numeric', 'gt:0', 'decimal:0,2'],
            'route_type' => ['sometimes', 'required', Rule::enum(LocationRouteType::class)],
            'dedicated_driver_id' => ['sometimes', 'nullable', 'integer'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                /** @var Location $location */
                $location = $this->route('location');
                $routeType = $this->input('route_type', $location->route_type->value);
                $driverId = match (true) {
                    $this->exists('dedicated_driver_id') => $this->input('dedicated_driver_id'),
                    $routeType === LocationRouteType::Open->value => null,
                    default => $location->dedicated_driver_id,
                };

                $this->validateRouteAssignment($validator, $routeType, $driverId);
            },
        ];
    }
}
