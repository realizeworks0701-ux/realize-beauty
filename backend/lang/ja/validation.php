<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| バリデーション言語行（日本語）
|--------------------------------------------------------------------------
|
| 画面に出るメッセージは日本語で統一する（ADR-030）。フロントエンドが 422 の
| メッセージをそのままフィールド下に表示するため、ここが利用者の目に触れる。
| 項目名は末尾の attributes で日本語化する。新しいカラムを追加したら
| attributes にも必ず1行足すこと。
|
*/

return [

    'accepted' => ':attributeを承認してください。',
    'accepted_if' => ':otherが:valueの場合、:attributeを承認してください。',
    'active_url' => ':attributeが有効なURLではありません。',
    'after' => ':attributeには:dateより後の日付を指定してください。',
    'after_or_equal' => ':attributeには:date以降の日付を指定してください。',
    'alpha' => ':attributeは英字のみで入力してください。',
    'alpha_dash' => ':attributeは英数字とハイフン、アンダースコアのみで入力してください。',
    'alpha_num' => ':attributeは英数字のみで入力してください。',
    'any_of' => ':attributeが正しくありません。',
    'array' => ':attributeは配列形式で指定してください。',
    'ascii' => ':attributeは半角英数字と記号のみで入力してください。',
    'before' => ':attributeには:dateより前の日付を指定してください。',
    'before_or_equal' => ':attributeには:date以前の日付を指定してください。',
    'between' => [
        'array' => ':attributeは:min個から:max個までにしてください。',
        'file' => ':attributeは:min KBから:max KBまでのファイルにしてください。',
        'numeric' => ':attributeは:minから:maxまでの数値にしてください。',
        'string' => ':attributeは:min文字から:max文字までで入力してください。',
    ],
    'boolean' => ':attributeはtrueかfalseで指定してください。',
    'can' => ':attributeに許可されていない値が含まれています。',
    'confirmed' => ':attributeが確認用の入力と一致しません。',
    'contains' => ':attributeに必要な値が含まれていません。',
    'current_password' => 'パスワードが正しくありません。',
    'date' => ':attributeは正しい日付を指定してください。',
    'date_equals' => ':attributeには:dateと同じ日付を指定してください。',
    'date_format' => ':attributeは:format形式で入力してください。',
    'decimal' => ':attributeは小数点以下:decimal桁で入力してください。',
    'declined' => ':attributeを拒否してください。',
    'declined_if' => ':otherが:valueの場合、:attributeを拒否してください。',
    'different' => ':attributeと:otherには異なる値を指定してください。',
    'digits' => ':attributeは:digits桁で入力してください。',
    'digits_between' => ':attributeは:min桁から:max桁までで入力してください。',
    'dimensions' => ':attributeの画像サイズが正しくありません。',
    'distinct' => ':attributeに重複した値があります。',
    'doesnt_contain' => ':attributeに次の値は使用できません: :values',
    'doesnt_end_with' => ':attributeの末尾に次の値は使用できません: :values',
    'doesnt_start_with' => ':attributeの先頭に次の値は使用できません: :values',
    'email' => ':attributeの形式が正しくありません。',
    'encoding' => ':attributeは:encodingでエンコードしてください。',
    'ends_with' => ':attributeの末尾は次のいずれかにしてください: :values',
    'enum' => '選択された:attributeは正しくありません。',
    'exists' => '選択された:attributeは存在しません。',
    'extensions' => ':attributeの拡張子は次のいずれかにしてください: :values',
    'file' => ':attributeはファイルを指定してください。',
    'filled' => ':attributeを入力してください。',
    'gt' => [
        'array' => ':attributeは:value個より多く指定してください。',
        'file' => ':attributeは:value KBより大きいファイルにしてください。',
        'numeric' => ':attributeは:valueより大きい数値にしてください。',
        'string' => ':attributeは:value文字より多く入力してください。',
    ],
    'gte' => [
        'array' => ':attributeは:value個以上指定してください。',
        'file' => ':attributeは:value KB以上のファイルにしてください。',
        'numeric' => ':attributeは:value以上の数値にしてください。',
        'string' => ':attributeは:value文字以上で入力してください。',
    ],
    'hex_color' => ':attributeは正しいカラーコードで入力してください。',
    'image' => ':attributeは画像ファイルを指定してください。',
    'in' => '選択された:attributeは正しくありません。',
    'in_array' => ':attributeが:otherに存在しません。',
    'in_array_keys' => ':attributeには次のいずれかのキーを含めてください: :values',
    'integer' => ':attributeは整数で入力してください。',
    'ip' => ':attributeは正しいIPアドレスを指定してください。',
    'ipv4' => ':attributeは正しいIPv4アドレスを指定してください。',
    'ipv6' => ':attributeは正しいIPv6アドレスを指定してください。',
    'json' => ':attributeは正しいJSON形式で指定してください。',
    'list' => ':attributeはリスト形式で指定してください。',
    'lowercase' => ':attributeは小文字で入力してください。',
    'lt' => [
        'array' => ':attributeは:value個より少なく指定してください。',
        'file' => ':attributeは:value KBより小さいファイルにしてください。',
        'numeric' => ':attributeは:valueより小さい数値にしてください。',
        'string' => ':attributeは:value文字より少なく入力してください。',
    ],
    'lte' => [
        'array' => ':attributeは:value個以下で指定してください。',
        'file' => ':attributeは:value KB以下のファイルにしてください。',
        'numeric' => ':attributeは:value以下の数値にしてください。',
        'string' => ':attributeは:value文字以内で入力してください。',
    ],
    'mac_address' => ':attributeは正しいMACアドレスを指定してください。',
    'max' => [
        'array' => ':attributeは:max個以内で指定してください。',
        'file' => ':attributeは:max KB以内のファイルにしてください。',
        'numeric' => ':attributeは:max以下の数値にしてください。',
        'string' => ':attributeは:max文字以内で入力してください。',
    ],
    'max_digits' => ':attributeは:max桁以内で入力してください。',
    'mimes' => ':attributeは次の形式のファイルにしてください: :values',
    'mimetypes' => ':attributeは次の形式のファイルにしてください: :values',
    'min' => [
        'array' => ':attributeは:min個以上指定してください。',
        'file' => ':attributeは:min KB以上のファイルにしてください。',
        'numeric' => ':attributeは:min以上の数値にしてください。',
        'string' => ':attributeは:min文字以上で入力してください。',
    ],
    'min_digits' => ':attributeは:min桁以上で入力してください。',
    'missing' => ':attributeは指定できません。',
    'missing_if' => ':otherが:valueの場合、:attributeは指定できません。',
    'missing_unless' => ':otherが:valueでない場合、:attributeは指定できません。',
    'missing_with' => ':valuesが指定されている場合、:attributeは指定できません。',
    'missing_with_all' => ':valuesがすべて指定されている場合、:attributeは指定できません。',
    'multiple_of' => ':attributeは:valueの倍数で入力してください。',
    'not_in' => '選択された:attributeは正しくありません。',
    'not_regex' => ':attributeの形式が正しくありません。',
    'numeric' => ':attributeは数値で入力してください。',
    'password' => [
        'letters' => ':attributeには英字を1文字以上含めてください。',
        'mixed' => ':attributeには大文字と小文字を1文字以上ずつ含めてください。',
        'numbers' => ':attributeには数字を1文字以上含めてください。',
        'symbols' => ':attributeには記号を1文字以上含めてください。',
        'uncompromised' => 'この:attributeは漏洩が確認されています。別の:attributeを設定してください。',
    ],
    'present' => ':attributeを指定してください。',
    'present_if' => ':otherが:valueの場合、:attributeを指定してください。',
    'present_unless' => ':otherが:valueでない場合、:attributeを指定してください。',
    'present_with' => ':valuesが指定されている場合、:attributeを指定してください。',
    'present_with_all' => ':valuesがすべて指定されている場合、:attributeを指定してください。',
    'prohibited' => ':attributeは指定できません。',
    'prohibited_if' => ':otherが:valueの場合、:attributeは指定できません。',
    'prohibited_if_accepted' => ':otherが承認されている場合、:attributeは指定できません。',
    'prohibited_if_declined' => ':otherが拒否されている場合、:attributeは指定できません。',
    'prohibited_unless' => ':otherが:valuesのいずれかでない場合、:attributeは指定できません。',
    'prohibits' => ':attributeを指定すると:otherは指定できません。',
    'regex' => ':attributeの形式が正しくありません。',
    'required' => ':attributeは必須です。',
    'required_array_keys' => ':attributeには次のキーを含めてください: :values',
    'required_if' => ':otherが:valueの場合、:attributeは必須です。',
    'required_if_accepted' => ':otherが承認されている場合、:attributeは必須です。',
    'required_if_declined' => ':otherが拒否されている場合、:attributeは必須です。',
    'required_unless' => ':otherが:valuesのいずれかでない場合、:attributeは必須です。',
    'required_with' => ':valuesが指定されている場合、:attributeは必須です。',
    'required_with_all' => ':valuesがすべて指定されている場合、:attributeは必須です。',
    'required_without' => ':valuesが指定されていない場合、:attributeは必須です。',
    'required_without_all' => ':valuesがいずれも指定されていない場合、:attributeは必須です。',
    'same' => ':attributeと:otherには同じ値を指定してください。',
    'size' => [
        'array' => ':attributeは:size個で指定してください。',
        'file' => ':attributeは:size KBのファイルにしてください。',
        'numeric' => ':attributeは:sizeにしてください。',
        'string' => ':attributeは:size文字で入力してください。',
    ],
    'starts_with' => ':attributeの先頭は次のいずれかにしてください: :values',
    'string' => ':attributeは文字列で入力してください。',
    'timezone' => ':attributeは正しいタイムゾーンを指定してください。',
    'unique' => 'この:attributeはすでに使用されています。',
    'uploaded' => ':attributeのアップロードに失敗しました。',
    'uppercase' => ':attributeは大文字で入力してください。',
    'url' => ':attributeの形式が正しくありません。',
    'ulid' => ':attributeは正しいULIDを指定してください。',
    'uuid' => ':attributeは正しいUUIDを指定してください。',

    /*
    |--------------------------------------------------------------------------
    | 項目別のカスタムメッセージ
    |--------------------------------------------------------------------------
    |
    | 'attribute-name' => ['rule-name' => 'メッセージ'] の形式で個別に上書きする。
    |
    */

    'custom' => [],

    /*
    |--------------------------------------------------------------------------
    | 項目名
    |--------------------------------------------------------------------------
    |
    | :attribute プレースホルダを日本語の項目名に置き換える。
    | ここに無いカラムはスネークケースのまま表示されるため、
    | FormRequest に項目を足したらこちらにも追加すること。
    |
    */

    'attributes' => [
        'birthday' => '生年月日',
        'blocks' => 'カルテ項目',
        'blocks.*.content' => 'カルテ項目の内容',
        'blocks.*.id' => 'カルテ項目のID',
        'blocks.*.label' => 'カルテ項目名',
        'blocks.*.sort_order' => 'カルテ項目の並び順',
        'business_hours' => '営業時間',
        'business_hours.*.close_time' => '終業時刻',
        'business_hours.*.day_of_week' => '曜日',
        'business_hours.*.is_closed' => '定休日',
        'business_hours.*.open_time' => '始業時刻',
        'calendar_id' => 'カレンダーID',
        'caption' => 'キャプション',
        'channel_access_token' => 'チャネルアクセストークン',
        'channel_id' => 'チャネルID',
        'channel_secret' => 'チャネルシークレット',
        'customer_id' => '顧客',
        'date' => '日付',
        'display_order' => '表示順',
        'duration_minutes' => '所要時間',
        'email' => 'メールアドレス',
        'from' => '開始日',
        'gender' => '性別',
        'image' => '画像',
        'is_active' => '有効',
        'is_first_visit' => '新規ご来店',
        'kana' => 'フリガナ',
        'keyword' => 'キーワード',
        'memo' => 'メモ',
        'menu_id' => 'メニュー',
        'mode' => 'モード',
        'name' => 'お名前',
        'note' => 'ご要望',
        'page' => 'ページ',
        'password' => 'パスワード',
        'per_page' => '表示件数',
        'phone' => '電話番号',
        'plan' => 'プラン',
        'price' => '価格',
        'session_id' => 'セッションID',
        'start_at' => '日時',
        'status' => 'ステータス',
        'to' => '終了日',
        'user_id' => '担当スタッフ',
        'visited_at' => '来店日',
    ],

];
