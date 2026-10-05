<?php

namespace Database\Factories;

use App\Enums\GenerationAttemptStatus;
use App\Models\GenerationAttempt;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GenerationAttempt>
 */
class GenerationAttemptFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'token_hash' => hash('sha256', fake()->unique()->uuid()),
            'project_id' => Project::factory(),
            'user_id' => fn (array $attributes): int => Project::findOrFail($attributes['project_id'])->user_id,
            'generated_content_id' => null,
            'target_generated_content_id' => null,
            'source_version_id' => null,
            'generated_content_version_id' => null,
            'status' => GenerationAttemptStatus::Issued,
            'claimed_at' => null,
            'completed_at' => null,
        ];
    }
}
