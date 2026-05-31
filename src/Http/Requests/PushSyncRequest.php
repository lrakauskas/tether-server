<?php

namespace Tether\Server\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PushSyncRequest extends FormRequest
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
            'client_id'                  => ['required', 'string'],
            'mutations'                  => ['required', 'array'],
            'mutations.*.mutation_id'    => ['required', 'string'],
            'mutations.*.entity_id'      => ['required', 'string'],
            'mutations.*.model'          => ['required', 'string'],
            'mutations.*.operation'      => ['required', Rule::in(['create', 'update', 'delete'])],
            'mutations.*.payload'        => ['present', 'array'],
            'mutations.*.version'        => ['required', 'integer', 'min:1'],
            'mutations.*.timestamp'      => ['required', 'integer'],
        ];
    }
}
