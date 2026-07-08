# Design System

## Theme

美容サロン向けCRMとして、白×くすみピンク×ベージュを基調とした柔らかく清潔感のあるデザインを採用する。

コンセプト

- 女性向けの上品で華やかなデザイン
- ガラス風カード（Glassmorphism）を取り入れる
- PrimeVueの操作感を保ちつつ、丸み・余白・アイコンで柔らかさを出す

---

## Color

### Primary（くすみピンク）

#D86C8A

ホバー: #C25373

### Pink Tint

- #FDF2F5（最淡）
- #FBE4EB
- #F6C9D6

### Beige

- #F3E9DD（最淡）
- #E9DAC8
- #D9C3AA

### Background

#FDF9F6（ウォームホワイト）

ピンク・ベージュの淡いラジアルグラデーションを重ねる。

### Surface（ガラス風カード）

- 背景: rgba(255, 255, 255, 0.72)
- ぼかし: backdrop-filter: blur(18px)
- 枠線: 1px solid rgba(255, 255, 255, 0.65)
- 影: 0 8px 32px rgba(216, 108, 138, 0.10)

### Border

#F0E4E8

### Text

- 基本: #4B4247（ウォームグレー）
- 補助: #9A8D91

---

## Gradient

KPIカード・主要ボタンにはグラデーションを使用する。

| 用途 | グラデーション |
| --- | --- |
| Rose | linear-gradient(135deg, #F7C8D3, #D86C8A) |
| Peach | linear-gradient(135deg, #F5DCC8, #DBA37E) |
| Mauve | linear-gradient(135deg, #EFD9E4, #B98AA6) |
| Cream | linear-gradient(135deg, #F6EBD4, #CBA96D) |

---

## Border Radius

- カード: 20px
- 入力・ボタン: 12px
- 写真サムネイル: 12px
- タグ・バッジ: 999px（ピル型）

---

## Shadow

ピンク系の柔らかい影を使用する。

0 8px 32px rgba(216, 108, 138, 0.10)

---

## Font

- 基本: Noto Sans JP
- ロゴ・見出しアクセント: Zen Maru Gothic

---

## Icon

PrimeIcons を使用し、ナビゲーション・ボタン・KPI・空状態へ積極的に配置する。

---

## Photo Layout

写真一覧はInstagram風のグリッドレイアウトとする。

- 正方形（aspect-ratio 1:1）
- 3カラム
- 間隔 6px
- ホバーでオーバーレイ表示（キャプション・削除）
- クリックで拡大表示

---

## Components

PrimeVue を使用する。

- Button
- Card
- DataTable
- Dialog
- Drawer
- Tabs
- InputText
- Textarea
- DatePicker
- Toast
- FileUpload
- Badge / Tag
- Avatar
- Skeleton
