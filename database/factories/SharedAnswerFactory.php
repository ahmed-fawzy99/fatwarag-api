<?php

namespace Database\Factories;

use App\Models\SharedAnswer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SharedAnswer>
 */
class SharedAnswerFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'public_id' => SharedAnswer::newPublicId(),
            'question' => 'ما حكم '.fake()->words(3, true).'؟',
            'follow_up' => null,
            'locale' => 'ar',
            'fatwa_ids' => ['islamweb-'.fake()->unique()->numberBetween(1000, 999999)],
            'summary' => null,
            'ip_hash' => hash('sha256', fake()->ipv4()),
        ];
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    public function withSummary(array $summary = []): static
    {
        return $this->state(fn () => ['summary' => $summary + [
            'kind' => 'agree',
            'headline' => 'Sources agree',
            'agreed' => null,
            'views' => [],
            'preferences' => [],
            'caution' => null,
            'extraFatwaIds' => [],
        ]]);
    }
}
