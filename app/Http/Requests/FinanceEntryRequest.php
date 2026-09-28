<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FinanceEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(['income', 'expense'])],
            'description' => ['required', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:100'],
            'amount' => ['required', 'string', 'regex:/\A(?:\d{1,3}(?:\.\d{3}){1,4}|\d{1,15})(?:,\d{1,2})?\z/', 'not_regex:/\A0+(?:,0{1,2})?\z/'],
            'occurred_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'payment_method' => ['nullable', Rule::in(['pix', 'cash', 'debit', 'credit', 'transfer', 'other'])],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function messages(): array
    {
        return [
            'amount.regex' => 'Informe um valor em reais, por exemplo 1.250,00.',
            'occurred_on.before_or_equal' => 'A data do lançamento não pode estar no futuro.',
        ];
    }
}
