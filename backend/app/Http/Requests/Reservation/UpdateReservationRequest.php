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
            'start_at' => ['sometimes', 'date'],
            'status' => ['sometimes', new Enum(ReservationStatus::class)],
            'note' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
