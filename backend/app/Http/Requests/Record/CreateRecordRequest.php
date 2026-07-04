<?php

namespace App\Http\Requests\Record;

use App\Enums\RecordStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class CreateRecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'visited_at' => ['required', 'date'],
            'status' => ['required', new Enum(RecordStatus::class)],
            'blocks' => ['required', 'array', 'min:1'],
            'blocks.*.label' => ['sometimes', 'required', 'string', 'max:100'],
            'blocks.*.content' => ['sometimes', 'required', 'string'],
            'blocks.*.sort_order' => ['sometimes', 'required', 'integer', 'min:0'],
        ];
    }
}
