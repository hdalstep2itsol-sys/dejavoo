<?php

namespace App\Http\Requests\Admin\Concerns;

trait NormalizesTerminalIdentifiers
{
    protected function normalizeTerminalIdentifiers(): void
    {
        $values = [];

        foreach (['tpn', 'term_id'] as $field) {
            if (! $this->exists($field)) {
                continue;
            }

            $value = $this->input($field);
            $values[$field] = is_string($value) && trim($value) !== ''
                ? trim($value)
                : null;
        }

        $this->merge($values);
    }
}
