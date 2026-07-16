<?php

namespace App\Http\Requests\PublicBooking;

use Illuminate\Foundation\Http\FormRequest;

class CreatePublicReservationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'menu_id' => ['required', 'integer'],
            'user_id' => ['nullable', 'integer'],
            // オフセット無しはUTC解釈で意図と9時間ずれるため、ISO 8601 オフセット付きのみ受理
            'start_at' => ['required', 'date_format:Y-m-d\TH:i:sP,Y-m-d\TH:i:s.vP'],
            'name' => ['required', 'string', 'max:100'],
            'kana' => ['required', 'string', 'max:100'],
            'phone' => ['required', 'string', 'max:20'],
        ];
    }
}
