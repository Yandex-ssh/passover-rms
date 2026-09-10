<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SubmitCustomerOrderRequest extends FormRequest
{
    /**
     * Customers do not need authentication to submit QR orders.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Validation rules for a customer order.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'items' => [
                'required',
                'array',
                'min:1',
            ],

            'items.*.menu_item_id' => [
                'required',
                'integer',
                'distinct',
                'exists:menu_items,id',
            ],

            'items.*.quantity' => [
                'required',
                'integer',
                'min:1',
                'max:99',
            ],

            'items.*.special_instruction' => [
                'nullable',
                'string',
                'max:255',
            ],

            'customer_note' => [
                'nullable',
                'string',
                'max:500',
            ],
        ];
    }
}