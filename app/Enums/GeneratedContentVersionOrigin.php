<?php

namespace App\Enums;

enum GeneratedContentVersionOrigin: string
{
    case AiGenerated = 'ai_generated';
    case UserEdited = 'user_edited';
}
