<?php

namespace Database\Factories;

use App\Models\Photo;
use App\Models\Record;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Photo>
 */
class PhotoFactory extends Factory
{
    protected $model = Photo::class;

    public function definition(): array
    {
        return [
            'record_id' => Record::factory(),
            'path' => 'photos/'.fake()->uuid().'.jpg',
            'caption' => fake()->optional()->words(2, true),
            'sort_order' => 0,
        ];
    }
}
