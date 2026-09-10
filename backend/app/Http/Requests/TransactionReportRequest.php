<?php

namespace App\Http\Requests;

class TransactionReportRequest extends ReportDateRangeRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'status' => ['nullable', 'in:open,partially_paid,paid,cancelled'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);
    }
}
