<?php

namespace App\Enums;

/**
 * サブスクリプションで利用可否を制御する機能単位（ADR-029）。
 *
 * 判定は必ずこのキーで行い、プラン名で分岐しない。
 * プラン→機能の対応表は config/billing.php が単一の正とする。
 */
enum Feature: string
{
    case Customer = 'customer';
    case MedicalRecord = 'medical_record';
    case Photo = 'photo';
    case Reservation = 'reservation';
    case GoogleCalendar = 'google_calendar';
    case Line = 'line';
    case AiSummary = 'ai_summary';
    case Analytics = 'analytics';

    public function label(): string
    {
        return match ($this) {
            self::Customer => '顧客管理',
            self::MedicalRecord => 'カルテ管理',
            self::Photo => '写真管理',
            self::Reservation => '予約管理',
            self::GoogleCalendar => 'Googleカレンダー連携',
            self::Line => 'LINE連携',
            self::AiSummary => 'AI要約',
            self::Analytics => '高度な分析',
        };
    }

    /**
     * この機能を含む最も安いプラン。アップグレード導線の文言に使う。
     * どのプランにも含まれない場合は null。
     */
    public function minimumPlan(): ?SubscriptionPlan
    {
        foreach (SubscriptionPlan::cases() as $plan) {
            if ($plan->includes($this)) {
                return $plan;
            }
        }

        return null;
    }
}
