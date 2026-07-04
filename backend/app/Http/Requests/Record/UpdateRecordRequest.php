<?php

namespace App\Http\Requests\Record;

use App\Enums\RecordStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class UpdateRecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'visited_at' => ['sometimes', 'date'],
            'status' => ['sometimes', new Enum(RecordStatus::class)],
            'blocks' => ['sometimes', 'array'],
            'blocks.*.id' => ['sometimes', 'nullable', 'integer'],
            'blocks.*.label' => ['sometimes', 'required_with:blocks', 'string', 'max:100'],
            'blocks.*.content' => ['sometimes', 'required_with:blocks', 'string'],
            'blocks.*.sort_order' => ['sometimes', 'required_with:blocks', 'integer', 'min:0'],
        ];
    }
}
