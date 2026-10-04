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

        if ($credential !== null) {
            try {
                $personalKey = $credential->api_key;
            } catch (DecryptException) {
                throw new OpenAiCredentialException('The saved OpenAI API key could not be decrypted. Replace it in Account settings.');
            }

            if (is_string($personalKey) && trim($personalKey) !== '') {
                return $personalKey;
            }
        }

        $serverFallbackKey = config('services.openai.api_key');

        if (filter_var(config('services.openai.allow_server_key_fallback', true), FILTER_VALIDATE_BOOLEAN)
            && is_string($serverFallbackKey)
            && trim($serverFallbackKey) !== '') {
            return trim($serverFallbackKey);
        }

        throw new OpenAiCredentialException('No usable OpenAI credential is available. Add a personal API key in Account settings or ask an administrator to check the server fallback configuration.');
    }
}
