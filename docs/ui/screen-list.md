# Screen List

| No | Screen                  | Route                         |
| -- | ----------------------- | ----------------------------- |
| 01 | Login                   | /login                        |
| 02 | Dashboard               | /dashboard                    |
| 03 | Customer List           | /customers                    |
| 04 | Customer Create         | /customers/create             |
| 05 | Customer Detail         | /customers/:id                |
| 06 | Customer Edit           | /customers/:id/edit           |
| 07 | Record List             | /customers/:id/records        |
| 08 | Record Create           | /customers/:id/records/create |
| 09 | Record Detail           | /records/:id                  |
| 10 | Record Edit             | /records/:id/edit             |
| 11 | Settings                | /settings                     |
| 12 | Reservation Calendar    | /reservations                 |
| 13 | Menu Settings           | /settings/menus               |
| 14 | Business Hours Settings | /settings/business-hours      |
| 15 | LINE Settings           | /settings/line                |
| 16 | Public Booking          | /booking/:slug                |
| 17 | Public Booking Cancel   | /booking/cancel/:token        |
| 18 | Google Calendar Settings| /settings/google-calendar     |

* No 16・17 は**認証なしでアクセスできる公開ページ**（顧客向け）。認証ガード対象外の公開ルートとし、PublicLayout（サイドバーなし・モバイルファースト）を使用する（[public-booking.md](public-booking.md)）
