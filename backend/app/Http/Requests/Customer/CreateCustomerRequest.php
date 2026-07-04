<?php

namespace App\Http\Requests\Customer;

use Illuminate\Foundation\Http\FormRequest;

class CreateCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'kana' => ['required', 'string', 'max:100'],
            'gender' => ['nullable', 'integer', 'in:0,1,2,9'],
            'birthday' => ['nullable', 'date'],
            'phone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:255'],
            'memo' => ['nullable', 'string'],
        ];
    }
}
