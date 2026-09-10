<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RecordCashPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->idempotency_key)) {
            $this->merge([
                'idempotency_key' => trim($this->idempotency_key),
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'amount_received' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'idempotency_key' => ['required', 'string', 'min:8', 'max:100'],
        ];
    }
}
