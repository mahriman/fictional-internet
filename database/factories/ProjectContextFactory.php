<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\ProjectContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProjectContext>
 */
class ProjectContextFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'setting' => fake()->optional()->paragraph(),
            'time_period' => fake()->optional()->sentence(),
            'locations' => fake()->optional()->paragraph(),
            'people' => fake()->optional()->paragraph(),
            'organizations' => fake()->optional()->paragraph(),
            'canon_notes' => fake()->optional()->paragraph(),
        ];
    }
}
