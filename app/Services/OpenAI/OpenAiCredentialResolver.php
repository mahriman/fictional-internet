<?php

namespace App\Services\OpenAI;

use App\Exceptions\OpenAiCredentialException;
use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;

class OpenAiCredentialResolver
{
    public function forUser(User $user): string
    {
        $credential = $user->openAiCredential()->first();

        if ($credential === null) {
            throw new OpenAiCredentialException('A personal OpenAI API key is required. Add one in Account settings.');
        }

        try {
            $personalKey = $credential->api_key;
        } catch (DecryptException) {
            throw new OpenAiCredentialException('The saved OpenAI API key could not be decrypted. Replace it in Account settings.');
        }

        if (is_string($personalKey) && trim($personalKey) !== '') {
            return $personalKey;
        }

        throw new OpenAiCredentialException('A personal OpenAI API key is required. Add one in Account settings.');
    }
}
