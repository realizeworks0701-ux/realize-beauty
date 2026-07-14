<?php

namespace App\Http\Requests\BusinessHour;

use Illuminate\Foundation\Http\FormRequest;

class UpdateBusinessHoursRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'business_hours' => ['required', 'array', 'size:7'],
            'business_hours.*.day_of_week' => ['required', 'integer', 'between:0,6', 'distinct'],
            'business_hours.*.is_closed' => ['required', 'boolean'],
            'business_hours.*.open_time' => ['required', 'date_format:H:i'],
            'business_hours.*.close_time' => ['required', 'date_format:H:i', 'after:business_hours.*.open_time'],
        ];
    }
}
