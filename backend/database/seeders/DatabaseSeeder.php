<?php

namespace Database\Seeders;

use App\Enums\ReservationStatus;
use App\Enums\Role;
use App\Models\BusinessHour;
use App\Models\Customer;
use App\Models\Menu;
use App\Models\Reservation;
use App\Models\Salon;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $salon = Salon::firstOrCreate(
            ['name' => 'Realize Beauty'],
            [
                'phone' => '03-1234-5678',
                'postal_code' => '150-0001',
                'address' => '東京都渋谷区神宮前1-1-1',
                'is_active' => true,
            ],
        );

        $owner = User::updateOrCreate(
            ['email' => 'admin@example.com'],
            [
                'salon_id' => $salon->id,
                'name' => '山田 太郎',
                'password' => Hash::make('password'),
                'role' => Role::Owner,
                'is_active' => true,
            ],
        );

        $staff = User::updateOrCreate(
            ['email' => 'staff@example.com'],
            [
                'salon_id' => $salon->id,
                'name' => '田中 美咲',
                'password' => Hash::make('password'),
                'role' => Role::Staff,
                'is_active' => true,
            ],
        );

        $menus = $this->seedMenus($salon);
        $this->seedBusinessHours($salon);
        $customers = $this->seedCustomers($salon);
        $this->seedReservations($salon, $menus, $customers, $owner, $staff);
    }

    /**
     * @return array<string, Menu>
     */
    private function seedMenus(Salon $salon): array
    {
        $definitions = [
            ['name' => 'カット', 'price' => 5500, 'duration_minutes' => 60, 'display_order' => 1],
            ['name' => 'カラー', 'price' => 8800, 'duration_minutes' => 90, 'display_order' => 2],
            ['name' => 'パーマ', 'price' => 12100, 'duration_minutes' => 120, 'display_order' => 3],
            ['name' => 'トリートメント', 'price' => 3300, 'duration_minutes' => 30, 'display_order' => 4],
        ];

        $menus = [];

        foreach ($definitions as $definition) {
            $menus[$definition['name']] = Menu::firstOrCreate(
                ['salon_id' => $salon->id, 'name' => $definition['name']],
                [
                    'price' => $definition['price'],
                    'duration_minutes' => $definition['duration_minutes'],
                    'display_order' => $definition['display_order'],
                    'is_active' => true,
                ],
            );
        }

        return $menus;
    }

    private function seedBusinessHours(Salon $salon): void
    {
        foreach (range(0, 6) as $dayOfWeek) {
            BusinessHour::updateOrCreate(
                ['salon_id' => $salon->id, 'day_of_week' => $dayOfWeek],
                [
                    'is_closed' => $dayOfWeek === 1,
                    'open_time' => '09:00',
                    'close_time' => '19:00',
                ],
            );
        }
    }

    /**
     * @return array<string, Customer>
     */
    private function seedCustomers(Salon $salon): array
    {
        $definitions = [
            ['name' => '佐藤 花子', 'kana' => 'サトウ ハナコ', 'gender' => 2, 'phone' => '090-1111-2222'],
            ['name' => '鈴木 一郎', 'kana' => 'スズキ イチロウ', 'gender' => 1, 'phone' => '090-3333-4444'],
            ['name' => '高橋 結衣', 'kana' => 'タカハシ ユイ', 'gender' => 2, 'phone' => '090-5555-6666'],
        ];

        $customers = [];

        foreach ($definitions as $definition) {
            $customers[$definition['name']] = Customer::firstOrCreate(
                ['salon_id' => $salon->id, 'name' => $definition['name']],
                [
                    'kana' => $definition['kana'],
                    'gender' => $definition['gender'],
                    'phone' => $definition['phone'],
                ],
            );
        }

        return $customers;
    }

    /**
     * @param  array<string, Menu>  $menus
     * @param  array<string, Customer>  $customers
     */
    private function seedReservations(Salon $salon, array $menus, array $customers, User $owner, User $staff): void
    {
        $today = Carbon::today(config('app.salon_timezone'));

        $definitions = [
            [
                'customer' => $customers['佐藤 花子'],
                'menu' => $menus['カット'],
                'user' => $owner,
                'start_at' => $today->copy()->setTime(10, 0),
                'status' => ReservationStatus::Reserved,
                'note' => '前回より短めのカット希望',
            ],
            [
                'customer' => $customers['鈴木 一郎'],
                'menu' => $menus['カラー'],
                'user' => $staff,
                'start_at' => $today->copy()->setTime(14, 0),
                'status' => ReservationStatus::Reserved,
                'note' => null,
            ],
            [
                'customer' => $customers['高橋 結衣'],
                'menu' => $menus['トリートメント'],
                'user' => $staff,
                'start_at' => $today->copy()->subDay()->setTime(11, 0),
                'status' => ReservationStatus::Visited,
                'note' => null,
            ],
            [
                'customer' => $customers['佐藤 花子'],
                'menu' => $menus['パーマ'],
                'user' => $owner,
                'start_at' => $today->copy()->addDay()->setTime(13, 0),
                'status' => ReservationStatus::Reserved,
                'note' => null,
            ],
        ];

        foreach ($definitions as $definition) {
            $startAt = $definition['start_at']->utc();

            Reservation::firstOrCreate(
                [
                    'salon_id' => $salon->id,
                    'user_id' => $definition['user']->id,
                    'start_at' => $startAt,
                ],
                [
                    'customer_id' => $definition['customer']->id,
                    'menu_id' => $definition['menu']->id,
                    'end_at' => $startAt->copy()->addMinutes($definition['menu']->duration_minutes),
                    'status' => $definition['status'],
                    'price' => $definition['menu']->price,
                    'note' => $definition['note'],
                ],
            );
        }
    }
}
