<?php

namespace App\Http\Requests\Admin\Concerns;

use App\Enums\LocationRouteType;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Validation\Validator;

trait ValidatesLocationRoute
{
    protected function validateRouteAssignment(
        Validator $validator,
        ?string $routeType,
        mixed $driverId,
    ): void {
        if ($routeType === LocationRouteType::Open->value && $driverId !== null) {
            $validator->errors()->add(
                'dedicated_driver_id',
                'An open route cannot have a dedicated driver.',
            );

            return;
        }

        if ($routeType !== LocationRouteType::Dedicated->value) {
            return;
        }

        if ($driverId === null || $driverId === '') {
            $validator->errors()->add(
                'dedicated_driver_id',
                'A dedicated route requires a driver.',
            );

            return;
        }

        if (filter_var($driverId, FILTER_VALIDATE_INT) === false) {
            return;
        }

        $isDriver = User::query()
            ->whereKey($driverId)
            ->where('role', UserRole::Driver->value)
            ->exists();

        if (! $isDriver) {
            $validator->errors()->add(
                'dedicated_driver_id',
                'The selected user must have the driver role.',
            );
        }
    }
}
