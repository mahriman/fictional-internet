<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreOpenAiCredentialRequest;
use App\Models\OpenAiCredential;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AccountSettingsController extends Controller
{
    public function show(Request $request): View
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return view('account.settings', [
            'hasPersonalKey' => $user->openAiCredential()->exists(),
            'serverFallbackEnabled' => filter_var(
                config('services.openai.allow_server_key_fallback', true),
                FILTER_VALIDATE_BOOLEAN,
            ),
        ]);
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

    public function destroy(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $user->openAiCredential()->delete();

        return redirect()
            ->route('account.settings')
            ->with('status', 'Your personal OpenAI API key has been removed.');
    }
}
