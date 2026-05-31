<?php

namespace Tether\Server\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PullSyncRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'client_id'        => ['required', 'string'],
            'last_sync_cursor' => ['nullable', 'integer', 'min:0'],
            'limit'            => ['nullable', 'integer', 'min:1'],
        ];
    }
}
