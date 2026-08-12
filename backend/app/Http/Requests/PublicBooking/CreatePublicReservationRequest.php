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
        // 「今日」はサロンTZ基準で決める（アプリTZ=UTC で判定すると JST 00:00〜09:00 に前日扱いになる）
        $today = now(config('app.salon_timezone'))->toDateString();

        return [
            'menu_id' => ['required', 'integer'],
            'user_id' => ['nullable', 'integer'],
            // オフセット無しはUTC解釈で意図と9時間ずれるため、ISO 8601 オフセット付きのみ受理
            'start_at' => ['required', 'date_format:Y-m-d\TH:i:sP,Y-m-d\TH:i:s.vP'],
            'name' => ['required', 'string', 'max:100'],
            'kana' => ['required', 'string', 'max:100'],
            'phone' => ['required', 'string', 'max:20'],
            // boolean ルールは必須。これが無いと exclude_unless が 'true' を文字列比較して常に除外される
            'is_first_visit' => ['required', 'boolean'],
            'birthday' => ['exclude_unless:is_first_visit,true', 'nullable', 'date_format:Y-m-d', 'before_or_equal:'.$today],
            'gender' => ['exclude_unless:is_first_visit,true', 'nullable', 'integer', 'in:0,1,2,9'],
            'email' => ['exclude_unless:is_first_visit,true', 'nullable', 'email', 'max:255'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
