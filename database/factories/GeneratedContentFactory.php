<?php

namespace Database\Factories;

use App\Models\GeneratedContent;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GeneratedContent>
 */
class GeneratedContentFactory extends Factory
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
            'content_type' => 'news_article',
            'title' => fake()->sentence(6),
        ];
    }
}
