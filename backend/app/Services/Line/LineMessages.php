<?php

namespace App\Services\Line;

use App\Models\Reservation;

/**
 * LINE 送信メッセージの文面を集約する。
 * 連携完了 reply には予約詳細を含めない（reply の情報最小化。ADR-024）。
 */
class LineMessages
{
    /**
     * @return array<string, mixed>
     */
    public static function followGreeting(): array
    {
        return self::text(implode("\n", [
            '友だち追加ありがとうございます。',
            'Web予約の完了画面に表示される6文字の連携コードをこのトークに送信すると、LINE連携が完了し、予約前日にリマインダーをお送りします。',
            '連携コードをお持ちでない場合は、次回のWeb予約時に発行されます。',
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    public static function linkCompleted(): array
    {
        return self::text('連携が完了しました。予約前日にリマインダーをお送りします。');
    }

    /**
     * @return array<string, mixed>
     */
    public static function alreadyLinked(): array
    {
        return self::text('このLINEアカウントは既に連携済みです。変更はサロンへお問い合わせください。');
    }

    /**
     * @return array<string, mixed>
     */
    public static function reservationReminder(Reservation $reservation): array
    {
        $start = $reservation->start_at->copy()->setTimezone(config('app.salon_timezone'));

        return self::text(implode("\n", [
            '明日のご予約のお知らせです。',
            '日時: '.$start->format('Y/n/j H:i').'〜',
            'メニュー: '.$reservation->menu->name,
            'ご来店をお待ちしております。',
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    public static function bookingConfirmation(Reservation $reservation): array
    {
        $start = $reservation->start_at->copy()->setTimezone(config('app.salon_timezone'));

        return self::text(implode("\n", [
            'ご予約を承りました。',
            '日時: '.$start->format('Y/n/j H:i').'〜',
            'メニュー: '.$reservation->menu->name,
            'ご来店をお待ちしております。',
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    private static function text(string $text): array
    {
        return ['type' => 'text', 'text' => $text];
    }
}
