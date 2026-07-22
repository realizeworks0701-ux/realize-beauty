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
