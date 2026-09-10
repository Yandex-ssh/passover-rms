<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RecordGcashPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $values = [];

        if (is_string($this->reference_number)) {
            $values['reference_number'] = trim($this->reference_number);
        }

        if (is_string($this->idempotency_key)) {
            $values['idempotency_key'] = trim($this->idempotency_key);
        }

        if ($values) {
            $this->merge($values);
        }
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'reference_number' => ['required', 'string', 'min:3', 'max:100'],
            'idempotency_key' => ['required', 'string', 'min:8', 'max:100'],
        ];
    }
}
