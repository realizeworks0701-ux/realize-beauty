<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Photo;
use App\Models\Record;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesSalonUsers;
use Tests\TestCase;

class PhotoApiTest extends TestCase
{
    use CreatesSalonUsers, RefreshDatabase;

    public function test_upload_stores_photo(): void
    {
        // 既定ディスク（本番は r2）に保存されることを固定する。ディスクを固定書きすると本番で URL と実体が食い違う
        $disk = Storage::fake(config('filesystems.default'));
        $user = $this->actingAsSalonUser();
        $record = Record::factory()->for($user->salon)->for($user)
            ->for(Customer::factory()->for($user->salon))->create();

        $response = $this->postJson("/api/v1/records/{$record->id}/photos", [
            'image' => UploadedFile::fake()->image('before.jpg'),
            'caption' => '施術前',
        ]);

        $response->assertCreated();
        $response->assertJsonStructure(['data' => ['id', 'url', 'caption', 'sort_order']]);
        $response->assertJsonPath('data.caption', '施術前');
        $this->assertDatabaseHas('photos', ['record_id' => $record->id, 'caption' => '施術前']);
        $disk->assertExists(Photo::firstOrFail()->path);
    }

    public function test_upload_requires_image(): void
    {
        $user = $this->actingAsSalonUser();
        $record = Record::factory()->for($user->salon)->for($user)
            ->for(Customer::factory()->for($user->salon))->create();

        $this->postJson("/api/v1/records/{$record->id}/photos", ['caption' => 'x'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('image');
    }

    public function test_upload_rejects_non_image(): void
    {
        $user = $this->actingAsSalonUser();
        $record = Record::factory()->for($user->salon)->for($user)
            ->for(Customer::factory()->for($user->salon))->create();

        $this->postJson("/api/v1/records/{$record->id}/photos", [
            'image' => UploadedFile::fake()->create('note.pdf', 100, 'application/pdf'),
        ])->assertStatus(422)->assertJsonValidationErrors('image');
    }

    public function test_destroy_deletes_photo(): void
    {
        Storage::fake(config('filesystems.default'));
        $user = $this->actingAsSalonUser();
        $record = Record::factory()->for($user->salon)->for($user)
            ->for(Customer::factory()->for($user->salon))->create();
        $photo = Photo::factory()->for($record)->create();

        $this->deleteJson("/api/v1/photos/{$photo->id}")->assertNoContent();
        $this->assertSoftDeleted('photos', ['id' => $photo->id]);
    }

    public function test_photo_operations_scoped_to_own_salon(): void
    {
        $this->actingAsSalonUser();
        $otherPhoto = Photo::factory()->create(); // 別サロンのカルテ写真

        $this->deleteJson("/api/v1/photos/{$otherPhoto->id}")->assertNotFound();
    }

    public function test_upload_requires_authentication(): void
    {
        $record = Record::factory()->create();
        $this->postJson("/api/v1/records/{$record->id}/photos", [
            'image' => UploadedFile::fake()->image('x.jpg'),
        ])->assertUnauthorized();
    }
}
