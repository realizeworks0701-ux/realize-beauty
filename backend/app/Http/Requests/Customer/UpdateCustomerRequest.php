<?php

namespace App\Http\Requests\Customer;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:100'],
            'kana' => ['sometimes', 'string', 'max:100'],
            'gender' => ['sometimes', 'nullable', 'integer', 'in:0,1,2,9'],
            'birthday' => ['sometimes', 'nullable', 'date'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:20'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'memo' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
