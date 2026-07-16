<?php

namespace App\Http\Requests\LineSetting;

use Illuminate\Foundation\Http\FormRequest;

class UpdateLineSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'channel_id' => ['required', 'string', 'max:100'],
            'channel_secret' => ['required', 'string', 'max:100'],
            'channel_access_token' => ['required', 'string', 'max:500'],
        ];
    }
}
