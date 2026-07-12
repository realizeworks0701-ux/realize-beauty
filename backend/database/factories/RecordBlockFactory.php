<?php

namespace Database\Factories;

use App\Models\Record;
use App\Models\RecordBlock;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecordBlock>
 */
class RecordBlockFactory extends Factory
{
    protected $model = RecordBlock::class;

    public function definition(): array
    {
        return [
            'record_id' => Record::factory(),
            'label' => fake()->randomElement(['施術内容', 'カウンセリング', '使用薬剤', '次回提案']),
            'content' => fake()->sentence(),
            'sort_order' => 0,
        ];
    }
}
