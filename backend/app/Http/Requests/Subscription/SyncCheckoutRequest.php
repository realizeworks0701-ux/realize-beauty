<?php

namespace App\Http\Requests\Subscription;

use Illuminate\Foundation\Http\FormRequest;

class SyncCheckoutRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'session_id' => ['required', 'string', 'max:255'],
        ];
    }

    public function sessionId(): string
    {
        return $this->validated('session_id');
    }
}
