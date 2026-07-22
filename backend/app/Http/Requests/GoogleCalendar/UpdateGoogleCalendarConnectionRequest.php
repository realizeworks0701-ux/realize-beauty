<?php

namespace App\Http\Requests\GoogleCalendar;

use Illuminate\Foundation\Http\FormRequest;

class UpdateGoogleCalendarConnectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // primary（エイリアス）または calendarList の実 id（メールアドレス形式）。存在検証は Service で行う
        return [
            'calendar_id' => ['required', 'string', 'max:1024'],
        ];
    }
}
