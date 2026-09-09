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
        ?int $existingDriverId = null,
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

        if ($existingDriverId !== null && (int) $driverId === $existingDriverId) {
            return;
        }

        $isDriver = User::query()
            ->whereKey($driverId)
            ->where('role', UserRole::Driver->value)
            ->where('is_active', true)
            ->exists();

        if (! $isDriver) {
            $validator->errors()->add(
                'dedicated_driver_id',
                'The selected user must be an active Driver.',
            );
        }
    }
}
