<?php

namespace App\Services\OpenAI;

enum OpenAiFailureKind: string
{
    case Authentication = 'authentication';
    case Authorization = 'authorization';
    case RateLimited = 'rate_limited';
    case TemporaryProvider = 'temporary_provider';
    case Network = 'network';
    case MalformedResponse = 'malformed_response';
    case IncompleteResponse = 'incomplete_response';
    case Refusal = 'refusal';
    case Configuration = 'configuration';
    case Other = 'other';
}
