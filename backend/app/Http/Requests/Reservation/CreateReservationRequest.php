<?php

namespace App\Http\Requests\Reservation;

use Illuminate\Foundation\Http\FormRequest;

class CreateReservationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'customer_id' => ['required', 'integer'],
            'menu_id' => ['required', 'integer'],
            'user_id' => ['required', 'integer'],
            // オフセット無しはUTC解釈で意図と9時間ずれるため、ISO 8601 オフセット付きのみ受理
            'start_at' => ['required', 'date_format:Y-m-d\TH:i:sP,Y-m-d\TH:i:s.vP'],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
