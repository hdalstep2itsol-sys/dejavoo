<?php

namespace App\Http\Requests\Admin;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssignTrailerLoadDriverRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'driver_id' => [
                'required',
                'integer',
                Rule::exists('users', 'id')->where('role', UserRole::Driver->value),
            ],
        ];
    }
}
