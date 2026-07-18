<?php

namespace App\Http\Requests\GoogleCalendar;

use Illuminate\Foundation\Http\FormRequest;

class ListGoogleBusyBlocksRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d'],
        ];
    }
}
