# Settings

## 概要

ユーザー設定画面。

---

## Route

`/settings`

---

## Components

* アカウント情報
* パスワード変更
* ログアウト
* サロン設定への導線カード
  * メニュー管理（/settings/menus へ遷移。[settings-menus.md](settings-menus.md)）
  * 営業時間設定（/settings/business-hours へ遷移。[settings-business-hours.md](settings-business-hours.md)）
  * LINE連携（/settings/line へ遷移。[settings-line.md](settings-line.md)。Web予約ページURLの表示もこのページに同居）
  * Googleカレンダー連携（/settings/google-calendar へ遷移。[settings-google-calendar.md](settings-google-calendar.md)）
  * プラン・お支払い（/settings/plan へ遷移。[settings-plan.md](settings-plan.md)）

サロン設定への導線カードは契約プランで出し分ける（[ADR-029](../decisions/ADR-029-subscription-billing.md)）。

| カード | 必要な機能 | 表示条件 |
|------|------|------|
| メニュー管理 | reservation | 予約管理を含むプラン（Standard 以上）でのみ表示 |
| 営業時間設定 | — | **常に表示**（サロンの基本情報として全プランに開放。API もプラン制限の対象外） |
| LINE連携 | line | LINE連携を含むプラン（Standard 以上）でのみ表示 |
| Googleカレンダー連携 | google_calendar | Googleカレンダー連携を含むプラン（Standard 以上）でのみ表示 |
| プラン・お支払い | — | **常に表示**（未契約・失効時の再契約導線を塞がないため） |

* メニューは予約機能の一部のため、独立した機能キーを持たず reservation で判定する
* カードを隠しても URL 直打ちは防げないため、その場合はルータが /plan-required/:feature へ振り替える（[plan-required.md](plan-required.md)）。遮断そのものは API の 403 が担う

---

## API

* GET /api/v1/auth/me
* POST /api/v1/auth/logout

---

## Actions

* 保存
* ログアウト
* メニュー管理へ
* 営業時間設定へ
* LINE連携へ
* Googleカレンダー連携へ
* プラン・お支払いへ
