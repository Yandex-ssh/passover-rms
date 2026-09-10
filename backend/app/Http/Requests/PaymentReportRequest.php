<?php

namespace App\Http\Requests;

class PaymentReportRequest extends ReportDateRangeRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'payment_method' => ['nullable', 'in:cash,gcash'],
        ]);
    }
}
