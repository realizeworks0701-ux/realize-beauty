<?php

namespace App\Http\Requests\GoogleCalendar;

use App\Enums\GoogleCalendarMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class SetGoogleCalendarModeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'mode' => ['required', new Enum(GoogleCalendarMode::class)],
        ];
    }
}
