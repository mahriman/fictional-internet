<?php

namespace Database\Factories;

use App\Models\OpenAiCredential;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OpenAiCredential>
 */
class OpenAiCredentialFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'api_key' => 'factory-test-secret',
        ];
    }
}
