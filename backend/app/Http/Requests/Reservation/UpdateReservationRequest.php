<?php

namespace App\Http\Requests\Reservation;

use App\Enums\ReservationStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class UpdateReservationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'customer_id' => ['sometimes', 'integer'],
            'menu_id' => ['sometimes', 'integer'],
            'user_id' => ['sometimes', 'integer'],
            // オフセット無しはUTC解釈で意図と9時間ずれるため、ISO 8601 オフセット付きのみ受理
            'start_at' => ['sometimes', 'date_format:Y-m-d\TH:i:sP,Y-m-d\TH:i:s.vP'],
            'status' => ['sometimes', new Enum(ReservationStatus::class)],
            'note' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
