<?php

namespace App\Modules\Finance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCashCountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $creating = $this->isMethod('post');

        return [
            'cash_box_id' => [$creating ? 'required' : 'prohibited', 'integer', 'exists:fin_cash_boxes,id'],
            'count_date' => [$creating ? 'required' : 'sometimes', 'date_format:Y-m-d', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'lines' => [$creating ? 'required' : 'sometimes', 'array', 'min:1', 'max:30'],
            'lines.*.currency_id' => ['required_without:lines.*.currency', 'nullable', 'integer'],
            'lines.*.currency' => ['required_without:lines.*.currency_id', 'nullable', 'string', 'size:3'],
            'lines.*.counted_amount' => ['required', 'numeric', 'min:-999999999999999', 'max:999999999999999'],
            'lines.*.explanation' => ['nullable', 'string', 'max:500'],
        ];
    }
}
