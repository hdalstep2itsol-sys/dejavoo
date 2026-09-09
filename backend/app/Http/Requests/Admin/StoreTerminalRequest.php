<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Admin\Concerns\NormalizesTerminalIdentifiers;
use Illuminate\Foundation\Http\FormRequest;

class StoreTerminalRequest extends FormRequest
{
    use NormalizesTerminalIdentifiers;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tpn' => ['nullable', 'string', 'max:64', 'required_without:term_id', 'unique:dejavoo_terminals,tpn'],
            'term_id' => ['nullable', 'string', 'max:64', 'required_without:tpn', 'unique:dejavoo_terminals,term_id'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->normalizeTerminalIdentifiers();
    }
}
