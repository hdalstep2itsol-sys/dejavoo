<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Admin\Concerns\NormalizesTerminalIdentifiers;
use App\Models\DejavooTerminal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateTerminalRequest extends FormRequest
{
    use NormalizesTerminalIdentifiers;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        /** @var DejavooTerminal $terminal */
        $terminal = $this->route('terminal');

        return [
            'tpn' => ['sometimes', 'nullable', 'string', 'max:64', Rule::unique('dejavoo_terminals', 'tpn')->ignore($terminal)],
            'term_id' => ['sometimes', 'nullable', 'string', 'max:64', Rule::unique('dejavoo_terminals', 'term_id')->ignore($terminal)],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                /** @var DejavooTerminal $terminal */
                $terminal = $this->route('terminal');
                $tpn = $this->exists('tpn') ? $this->input('tpn') : $terminal->tpn;
                $termId = $this->exists('term_id') ? $this->input('term_id') : $terminal->term_id;

                if ($tpn === null && $termId === null) {
                    $validator->errors()->add(
                        'tpn',
                        'A terminal mapping requires a TPN or Terminal ID.',
                    );
                }
            },
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->normalizeTerminalIdentifiers();
    }
}
