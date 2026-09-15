<?php

namespace App\Http\Requests\Webhooks;

use Illuminate\Foundation\Http\FormRequest;

class IpospaysFeedRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'signature' => ['required', 'string'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function feedPayload(): array
    {
        $payload = $this->attributes->get('ipospays_feed_payload');

        return is_array($payload) ? $payload : [];
    }
}
