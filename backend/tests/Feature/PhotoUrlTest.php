<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Photo;
use App\Models\Record;
use App\Models\Salon;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PhotoUrlTest extends TestCase
{
    use RefreshDatabase;

    private function makePhoto(): Photo
    {
        $salon = Salon::factory()->create();
        $user = User::factory()->for($salon)->create();
        $record = Record::factory()->for($salon)->for($user)
            ->for(Customer::factory()->for($salon))->create();

        return Photo::factory()->for($record)->create(['path' => 'photos/secret.jpg']);
    }

    public function test_url_is_signed_and_expiring_on_a_private_disk(): void
    {
        $disk = config('filesystems.default');
        Storage::fake($disk);
        config(["filesystems.disks.{$disk}.visibility" => 'private']);
        Storage::disk()->buildTemporaryUrlsUsing(
            fn (string $path, $expiration) => 'https://signed.example.test/'.$path.'?expires='.$expiration->getTimestamp(),
        );

        $url = $this->makePhoto()->url;

        $this->assertStringStartsWith('https://signed.example.test/photos/secret.jpg', $url);
        $this->assertStringContainsString('expires=', $url);
    }

    public function test_url_stays_a_plain_url_on_a_public_disk(): void
    {
        $disk = config('filesystems.default');
        Storage::fake($disk);
        config(["filesystems.disks.{$disk}.visibility" => 'public']);

        $this->assertSame('/storage/photos/secret.jpg', $this->makePhoto()->url);
    }
}
