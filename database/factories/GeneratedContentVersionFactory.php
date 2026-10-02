<?php

namespace Database\Factories;

use App\Enums\GeneratedContentVersionOrigin;
use App\Models\GeneratedContent;
use App\Models\GeneratedContentVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GeneratedContentVersion>
 */
class GeneratedContentVersionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'generated_content_id' => GeneratedContent::factory(),
            'version_number' => 1,
            'origin' => GeneratedContentVersionOrigin::AiGenerated,
            'content' => [
                'headline' => fake()->sentence(),
                'publication' => fake()->company(),
                'published_at' => now()->toIso8601String(),
                'body' => fake()->paragraph(),
            ],
            'context_snapshot' => null,
            'generation_metadata' => null,
        ];
    }

    public function userEdited(): static
    {
        return $this->state(fn (array $attributes) => [
            'origin' => GeneratedContentVersionOrigin::UserEdited,
        ]);
    }
}
