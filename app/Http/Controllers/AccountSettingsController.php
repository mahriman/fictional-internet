<?php

namespace App\Http\Controllers;

use App\Http\Requests\DeleteAccountRequest;
use App\Http\Requests\StoreOpenAiCredentialRequest;
use App\Http\Requests\UpdateAccountPasswordRequest;
use App\Http\Requests\UpdateAccountProfileRequest;
use App\Models\OpenAiCredential;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class AccountSettingsController extends Controller
{
    public function show(Request $request): View
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return view('account.settings', [
            'user' => $user,
            'hasPersonalKey' => $user->openAiCredential()->exists(),
        ]);
    }

    public function updateProfile(UpdateAccountProfileRequest $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $profile = $request->validated();

        $user->name = $profile['name'];
        $user->email = $profile['email'];
        $user->save();

        return redirect()
            ->route('account.settings')
            ->with('status', 'Your account details have been updated.');
    }

    public function updatePassword(UpdateAccountPasswordRequest $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $user->forceFill(['password' => $request->validated('password')])->save();

        return redirect()
            ->route('account.settings')
            ->with('status', 'Your password has been changed.');
    }

    public function store(StoreOpenAiCredentialRequest $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $credential = $user->openAiCredential()->first() ?? new OpenAiCredential;
        $credential->api_key = $request->validated('api_key');
        $user->openAiCredential()->save($credential);

        return redirect()
            ->route('account.settings')
            ->with('status', 'Your personal OpenAI API key has been saved.');
    }

    public function removeOpenAiCredential(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $user->openAiCredential()->delete();

        return redirect()
            ->route('account.settings')
            ->with('status', 'Your personal OpenAI API key has been removed.');
    }

    public function destroy(DeleteAccountRequest $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        Auth::logout();

        DB::transaction(function () use ($user): void {
            if (config('session.driver') === 'database') {
                DB::table(config('session.table', 'sessions'))
                    ->where('user_id', $user->getKey())
                    ->delete();
            }

            $user->delete();
        });

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()
            ->route('home')
            ->with('status', 'Your account has been deleted.');
    }
}
