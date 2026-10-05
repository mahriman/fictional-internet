<?php

use App\Models\GeneratedContent;
use App\Models\GeneratedContentVersion;
use App\Models\GenerationAttempt;
use App\Models\OpenAiCredential;
use App\Models\Project;
use App\Models\ProjectContext;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword as ResetPasswordNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function accountSettingsUrl(): string
{
    return route('account.settings');
}

test('account settings show profile password and existing personal credential state', function () {
    $user = User::factory()->create(['name' => 'Morgan Reed', 'email' => 'morgan@example.test']);
    OpenAiCredential::factory()->for($user)->create(['api_key' => 'account-display-secret']);

    $this->actingAs($user)
        ->get(accountSettingsUrl())
        ->assertOk()
        ->assertSee('Profile')
        ->assertSee('Password')
        ->assertSee('OpenAI API key')
        ->assertSee('A personal key is configured.')
        ->assertSee('Morgan Reed')
        ->assertSee('morgan@example.test')
        ->assertSee(route('account.settings.destroy'))
        ->assertDontSee('account-display-secret')
        ->assertDontSee('value="'.$user->password.'"', false);
});

test('owners can update only their trimmed name and unique email without changing verification state', function () {
    $user = User::factory()->create(['email' => 'old@example.test']);
    $otherUser = User::factory()->create(['email' => 'other@example.test']);
    $credential = new OpenAiCredential;
    $credential->api_key = 'profile-preserved-secret';
    $user->openAiCredential()->save($credential);
    $verifiedAt = $user->email_verified_at;

    $this->actingAs($user)
        ->patch(route('account.settings.profile.update'), [
            'name' => '  New Name  ',
            'email' => '  new@example.test  ',
            'user_id' => $otherUser->getKey(),
            'id' => $otherUser->getKey(),
            'password' => 'must-not-change-password',
            'access_level' => 'admin',
            'api_key' => 'must-not-change-credential',
        ])
        ->assertRedirect(accountSettingsUrl())
        ->assertSessionHas('status', 'Your account details have been updated.');

    expect($user->fresh()->name)->toBe('New Name')
        ->and($user->fresh()->email)->toBe('new@example.test')
        ->and($user->fresh()->email_verified_at?->equalTo($verifiedAt))->toBeTrue()
        ->and(Hash::check('must-not-change-password', $user->fresh()->password))->toBeFalse()
        ->and($user->openAiCredential()->firstOrFail()->api_key)->toBe('profile-preserved-secret')
        ->and($otherUser->fresh()->email)->toBe('other@example.test');
});

test('profile validation rejects duplicate and blank email while flashing only supported profile fields', function () {
    $user = User::factory()->create(['name' => 'Current Name', 'email' => 'current@example.test']);
    User::factory()->create(['email' => 'taken@example.test']);

    $this->actingAs($user)
        ->from(accountSettingsUrl())
        ->patch(route('account.settings.profile.update'), [
            'name' => 'Updated Name',
            'email' => 'taken@example.test',
            'password' => 'private-profile-password',
            'api_key' => 'private-profile-key',
        ])
        ->assertRedirect(accountSettingsUrl())
        ->assertSessionHasErrors('email')
        ->assertSessionHas('_old_input.name', 'Updated Name')
        ->assertSessionHas('_old_input.email', 'taken@example.test')
        ->assertSessionMissing('_old_input.password')
        ->assertSessionMissing('_old_input.api_key');

    expect($user->fresh()->name)->toBe('Current Name')
        ->and($user->fresh()->email)->toBe('current@example.test');
});

test('users can change their password with current password and confirmation while preserving owned data', function () {
    $user = User::factory()->create(['password' => 'current-password']);
    $project = Project::factory()->for($user)->create();
    $content = GeneratedContent::factory()->for($project)->create();
    $version = GeneratedContentVersion::factory()->for($content)->create();
    $credential = new OpenAiCredential;
    $credential->api_key = 'password-change-preserved-key';
    $user->openAiCredential()->save($credential);

    $this->actingAs($user)
        ->put(route('account.settings.password.update'), [
            'current_password' => 'current-password',
            'password' => 'replacement-password',
            'password_confirmation' => 'replacement-password',
        ])
        ->assertRedirect(accountSettingsUrl())
        ->assertSessionHas('status', 'Your password has been changed.');

    expect(Hash::check('replacement-password', $user->fresh()->password))->toBeTrue()
        ->and(Hash::check('current-password', $user->fresh()->password))->toBeFalse()
        ->and($user->projects()->count())->toBe(1)
        ->and($project->generatedContents()->count())->toBe(1)
        ->and($content->versions()->count())->toBe(1)
        ->and($version->fresh()->content)->toBeArray()
        ->and($user->openAiCredential()->firstOrFail()->api_key)->toBe('password-change-preserved-key');
});

test('password change rejects wrong current password confirmation failures and weak passwords without flashing secrets', function () {
    $user = User::factory()->create(['password' => 'current-password']);
    $this->actingAs($user);

    $this->from(accountSettingsUrl())
        ->put(route('account.settings.password.update'), [
            'current_password' => 'wrong-current-password',
            'password' => 'new-password-secret',
            'password_confirmation' => 'different-password-secret',
        ])
        ->assertRedirect(accountSettingsUrl())
        ->assertSessionHasErrors(['current_password', 'password'])
        ->assertSessionMissing('_old_input');

    $this->from(accountSettingsUrl())
        ->put(route('account.settings.password.update'), [
            'current_password' => 'current-password',
            'password' => 'short',
            'password_confirmation' => 'short',
        ])
        ->assertRedirect(accountSettingsUrl())
        ->assertSessionHasErrors('password')
        ->assertSessionMissing('_old_input');

    expect(Hash::check('current-password', $user->fresh()->password))->toBeTrue();
});

test('forgot password request uses the configured broker and sends a reset notification without account enumeration', function () {
    Notification::fake();
    $user = User::factory()->create(['email' => 'recover@example.test']);
    $this->get(route('login'))->assertOk()->assertSee(route('password.request'))->assertSee('Forgot password?');
    $this->get(route('password.request'))->assertOk()->assertSee('Enter the email address used to sign in.');

    $validResponse = $this->post(route('password.email'), ['email' => $user->email]);
    $unknownResponse = $this->post(route('password.email'), ['email' => 'missing@example.test']);

    $validResponse->assertRedirect(route('password.request'))
        ->assertSessionHas('status', 'If an account exists for that email, password reset instructions will be sent.');
    $unknownResponse->assertRedirect(route('password.request'))
        ->assertSessionHas('status', 'If an account exists for that email, password reset instructions will be sent.');
    Notification::assertSentToOnce($user, ResetPasswordNotification::class);
    Notification::assertCount(1);
    expect(config('auth.passwords.'.config('auth.defaults.passwords').'.table'))->toBe('password_reset_tokens');
});

test('reset-link requests are throttled by Laravel broker and HTTP middleware without revealing account state', function () {
    Notification::fake();
    $user = User::factory()->create(['email' => 'throttled@example.test']);

    $this->post(route('password.email'), ['email' => $user->email])->assertSessionHas('status');
    $this->post(route('password.email'), ['email' => $user->email])
        ->assertSessionHas('status', 'If an account exists for that email, password reset instructions will be sent.');

    Notification::assertSentToOnce($user, ResetPasswordNotification::class);
});

test('valid reset link renders the token form and resets the password once using a hashed broker token', function () {
    Notification::fake();
    $user = User::factory()->create(['email' => 'reset@example.test', 'password' => 'before-reset-password']);

    $this->post(route('password.email'), ['email' => $user->email])->assertSessionHas('status');
    Notification::assertSentToOnce($user, ResetPasswordNotification::class);
    $notification = Notification::sent($user, ResetPasswordNotification::class)->sole();
    $storedToken = DB::table(config('auth.passwords.'.config('auth.defaults.passwords').'.table'))
        ->where('email', $user->email)
        ->value('token');
    $resetUrl = route('password.reset', ['token' => $notification->token, 'email' => $user->email]);

    $this->get($resetUrl)
        ->assertOk()
        ->assertSee('name="token"', false)
        ->assertSee('name="password"', false)
        ->assertSee('autocomplete="new-password"', false)
        ->assertDontSee('before-reset-password');

    expect($storedToken)->not->toBe($notification->token);

    $this->post(route('password.update'), [
        'email' => $user->email,
        'token' => $notification->token,
        'password' => 'after-reset-password',
        'password_confirmation' => 'after-reset-password',
    ])->assertRedirect(route('login'))
        ->assertSessionHas('status', 'Your password has been reset. Sign in with your new password.');

    expect(Hash::check('after-reset-password', $user->fresh()->password))->toBeTrue()
        ->and(DB::table(config('auth.passwords.'.config('auth.defaults.passwords').'.table'))->where('email', $user->email)->exists())->toBeFalse();

    $this->post(route('password.update'), [
        'email' => $user->email,
        'token' => $notification->token,
        'password' => 'another-reset-password',
        'password_confirmation' => 'another-reset-password',
    ])->assertSessionHasErrors('email')
        ->assertSessionMissing('_old_input');

    expect(Hash::check('after-reset-password', $user->fresh()->password))->toBeTrue();
});

test('invalid reset tokens and password validation failures never flash password or token input', function () {
    $user = User::factory()->create(['email' => 'invalid-reset@example.test', 'password' => 'unchanged-password']);

    $this->from(route('password.reset', ['token' => 'invalid-reset-token', 'email' => $user->email]))
        ->post(route('password.update'), [
            'email' => $user->email,
            'token' => 'invalid-reset-token',
            'password' => 'reset-secret-password',
            'password_confirmation' => 'different-secret-password',
        ])
        ->assertRedirect(route('password.reset', ['token' => 'invalid-reset-token', 'email' => $user->email]))
        ->assertSessionHasErrors('password')
        ->assertSessionMissing('_old_input');

    $this->from(route('password.reset', ['token' => 'invalid-reset-token', 'email' => $user->email]))
        ->post(route('password.update'), [
            'email' => $user->email,
            'token' => 'invalid-reset-token',
            'password' => 'reset-secret-password',
            'password_confirmation' => 'reset-secret-password',
        ])
        ->assertRedirect(route('password.reset', ['token' => 'invalid-reset-token', 'email' => $user->email]))
        ->assertSessionHasErrors('email')
        ->assertSessionMissing('_old_input');

    expect(Hash::check('unchanged-password', $user->fresh()->password))->toBeTrue();
});

test('account deletion requires current password and explicit confirmation', function () {
    $user = User::factory()->create(['password' => 'deletion-password']);
    $this->actingAs($user);

    $this->delete(route('account.settings.destroy'), [
        'current_password' => 'wrong-password',
        'confirm_deletion' => '1',
    ])->assertSessionHasErrors('current_password');

    $this->delete(route('account.settings.destroy'), [
        'current_password' => 'deletion-password',
    ])->assertSessionHasErrors('confirm_deletion')
        ->assertSessionMissing('_old_input');

    $this->assertModelExists($user);
    $this->assertAuthenticatedAs($user);
});

test('account deletion removes only owned data cascades credentials and attempts and logs out the user', function () {
    config(['session.driver' => 'database']);
    $user = User::factory()->create(['password' => 'deletion-password']);
    $otherUser = User::factory()->create();
    $project = Project::factory()->for($user)->create();
    ProjectContext::factory()->for($project)->create();
    $content = GeneratedContent::factory()->for($project)->create();
    $version = GeneratedContentVersion::factory()->for($content)->create();
    $credential = new OpenAiCredential;
    $credential->api_key = 'delete-this-encrypted-credential';
    $user->openAiCredential()->save($credential);
    $attempt = GenerationAttempt::factory()->create([
        'project_id' => $project->getKey(),
        'user_id' => $user->getKey(),
        'generated_content_id' => $content->getKey(),
        'token_hash' => hash('sha256', 'delete-this-attempt'),
    ]);
    $otherProject = Project::factory()->for($otherUser)->create();
    $otherContent = GeneratedContent::factory()->for($otherProject)->create();
    $sessionId = (string) Str::uuid();
    DB::table('sessions')->insert([
        'id' => $sessionId,
        'user_id' => $user->getKey(),
        'ip_address' => '127.0.0.1',
        'user_agent' => 'account lifecycle test',
        'payload' => '',
        'last_activity' => now()->timestamp,
    ]);

    $this->actingAs($user)
        ->delete(route('account.settings.destroy'), [
            'current_password' => 'deletion-password',
            'confirm_deletion' => '1',
            'user_id' => $otherUser->getKey(),
        ])
        ->assertRedirect(route('home'))
        ->assertSessionHas('status', 'Your account has been deleted.');

    $this->assertGuest();
    $this->assertDatabaseMissing('users', ['id' => $user->getKey()]);
    $this->assertDatabaseMissing('open_ai_credentials', ['id' => $credential->getKey()]);
    $this->assertDatabaseMissing('projects', ['id' => $project->getKey()]);
    $this->assertDatabaseMissing('project_contexts', ['project_id' => $project->getKey()]);
    $this->assertDatabaseMissing('generated_contents', ['id' => $content->getKey()]);
    $this->assertDatabaseMissing('generated_content_versions', ['id' => $version->getKey()]);
    $this->assertDatabaseMissing('generation_attempts', ['id' => $attempt->getKey()]);
    $this->assertDatabaseMissing('sessions', ['id' => $sessionId]);
    $this->assertDatabaseHas('users', ['id' => $otherUser->getKey()]);
    $this->assertDatabaseHas('projects', ['id' => $otherProject->getKey()]);
    $this->assertDatabaseHas('generated_contents', ['id' => $otherContent->getKey()]);
});

test('guests cannot open account settings or invoke account lifecycle writes', function () {
    $this->get(accountSettingsUrl())->assertRedirect(route('login'));
    $this->patch(route('account.settings.profile.update'), ['name' => 'Someone', 'email' => 'someone@example.test'])
        ->assertRedirect(route('login'));
    $this->put(route('account.settings.password.update'), [])->assertRedirect(route('login'));
    $this->delete(route('account.settings.destroy'), [])->assertRedirect(route('login'));
});

test('account deletion settings point users to per-project export without calling it an account backup', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(accountSettingsUrl())
        ->assertOk()
        ->assertSeeText("You can export each project's data before deleting your account.")
        ->assertSee('they are not a full account backup')
        ->assertSee('exclude account/authentication data and credentials')
        ->assertSee(route('projects.index'));
});

test('project export remains project-centric after profile and credential changes', function () {
    $user = User::factory()->create(['name' => 'Private Export Name', 'email' => 'private-export@example.test']);
    $project = Project::factory()->for($user)->create();
    $credential = new OpenAiCredential;
    $credential->api_key = 'private-export-key-sentinel';
    $user->openAiCredential()->save($credential);

    $this->actingAs($user)->patch(route('account.settings.profile.update'), [
        'name' => 'Changed Private Export Name',
        'email' => 'changed-private-export@example.test',
    ])->assertRedirect(accountSettingsUrl());

    $response = $this->get(route('projects.export', $project))->assertOk();
    $json = $response->streamedContent();

    expect($json)
        ->not->toContain(
            'Changed Private Export Name',
            'changed-private-export@example.test',
            'private-export-key-sentinel',
            $credential->getRawOriginal('api_key'),
        );
});
