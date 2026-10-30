<?php

namespace Database\Factories;

use App\Models\CommonQuestion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CommonQuestion>
 */
class CommonQuestionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'position' => fake()->unique()->numberBetween(1, 1000),
            'question_ar' => 'هل تجب الزكاة في الذهب الملبوس للزينة؟',
            'question_en' => 'Is zakat due on gold jewellery worn for adornment?',
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
