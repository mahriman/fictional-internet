<?php

use App\Models\OpenAiCredential;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;

uses(RefreshDatabase::class);

test('users can save a trimmed personal key encrypted and hidden from serialization and settings html', function () {
    $user = User::factory()->create();
    $key = '  test-personal-secret-value  ';

    $this->actingAs($user)
        ->put(route('account.settings.openai-credential.store'), ['api_key' => $key])
        ->assertRedirect(route('account.settings'))
        ->assertSessionHas('status', 'Your personal OpenAI API key has been saved.');

    $credential = $user->openAiCredential()->firstOrFail();
    $ciphertext = $credential->getRawOriginal('api_key');

    expect(is_string($ciphertext))->toBeTrue()
        ->and($ciphertext === trim($key))->toBeFalse()
        ->and(Crypt::decryptString($ciphertext) === trim($key))->toBeTrue()
        ->and($credential->api_key === trim($key))->toBeTrue()
        ->and($credential->toArray())->not->toHaveKey('api_key')
        ->and(json_encode($credential, JSON_THROW_ON_ERROR))->not->toContain($ciphertext)
        ->and(json_encode($credential, JSON_THROW_ON_ERROR))->not->toContain(trim($key));

    $this->get(route('account.settings'))
        ->assertOk()
        ->assertSee('A personal key is configured.')
        ->assertSee('does not use a shared server key as a fallback.')
        ->assertSee('name="api_key"', false)
        ->assertSee('type="password"', false)
        ->assertSee('autocomplete="new-password"', false)
        ->assertDontSee('value="'.trim($key).'"', false)
        ->assertDontSee($ciphertext, false)
        ->assertDontSee(trim($key), false);
});

test('guests are redirected and authenticated users can only manage their own key', function () {
    $owner = User::factory()->create();
    $otherUser = User::factory()->create();
    OpenAiCredential::factory()->for($owner)->create(['api_key' => 'owner-test-secret']);

    $this->get(route('account.settings'))->assertRedirect(route('login'));
    $this->put(route('account.settings.openai-credential.store'), ['api_key' => 'guest-test-secret'])
        ->assertRedirect(route('login'));

    $this->actingAs($otherUser)
        ->get(route('account.settings'))
        ->assertOk()
        ->assertSee('No personal key is configured.')
        ->assertSee('does not use a shared server key as a fallback.')
        ->assertDontSee('owner-test-secret');

    $this->put(route('account.settings.openai-credential.store'), [
        'api_key' => 'other-users-own-secret',
        'user_id' => $owner->id,
    ])->assertRedirect(route('account.settings'));

    expect($owner->openAiCredential()->firstOrFail()->api_key === 'owner-test-secret')->toBeTrue()
        ->and($otherUser->openAiCredential()->firstOrFail()->api_key === 'other-users-own-secret')->toBeTrue()
        ->and(OpenAiCredential::query()->count())->toBe(2);

    $this->delete(route('account.settings.openai-credential.destroy'), ['user_id' => $owner->id])
        ->assertRedirect(route('account.settings'));

    expect($owner->openAiCredential()->exists())->toBeTrue()
        ->and($otherUser->openAiCredential()->exists())->toBeFalse();
});

test('invalid key input is not flashed and accepted keys are not restricted by prefix', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $oversizedValue = str_repeat('x', 513);

    $this->from(route('account.settings'))
        ->put(route('account.settings.openai-credential.store'), ['api_key' => $oversizedValue])
        ->assertRedirect(route('account.settings'))
        ->assertSessionHasErrors('api_key')
        ->assertSessionMissing('_old_input.api_key');

    $this->from(route('account.settings'))
        ->put(route('account.settings.openai-credential.store'), ['api_key' => '   '])
        ->assertRedirect(route('account.settings'))
        ->assertSessionHasErrors('api_key')
        ->assertSessionMissing('_old_input.api_key');

    expect($user->openAiCredential()->exists())->toBeFalse();

    $this->put(route('account.settings.openai-credential.store'), ['api_key' => 'custom-key-format'])
        ->assertRedirect(route('account.settings'));

    expect($user->openAiCredential()->firstOrFail()->api_key === 'custom-key-format')->toBeTrue();
});

test('users can replace an existing key and safely remove it more than once', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    OpenAiCredential::factory()->for($user)->create(['api_key' => 'first-test-secret']);

    $this->put(route('account.settings.openai-credential.store'), ['api_key' => 'second-test-secret'])
        ->assertRedirect(route('account.settings'));

    expect($user->openAiCredential()->count())->toBe(1)
        ->and($user->openAiCredential()->firstOrFail()->api_key === 'second-test-secret')->toBeTrue();

    $this->get(route('account.settings'))->assertSee('A personal key is configured.');
    $this->delete(route('account.settings.openai-credential.destroy'))
        ->assertRedirect(route('account.settings'))
        ->assertSessionHas('status', 'Your personal OpenAI API key has been removed.');
    $this->delete(route('account.settings.openai-credential.destroy'))
        ->assertRedirect(route('account.settings'));

    expect($user->openAiCredential()->exists())->toBeFalse();
});

test('deleting a user cascades to the stored credential', function () {
    $user = User::factory()->create();
    $credential = OpenAiCredential::factory()->for($user)->create(['api_key' => 'cascade-test-secret']);

    $user->delete();

    $this->assertDatabaseMissing('open_ai_credentials', ['id' => $credential->id]);
});

test('the account settings link is present in authenticated application navigation', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('projects.index'))
        ->assertOk()
        ->assertSee(route('account.settings'), false)
        ->assertSee('Account settings');
});
