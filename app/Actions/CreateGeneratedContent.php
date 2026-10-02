<?php

namespace App\Actions;

use App\ContentTypes\ContentTypeRegistry;
use App\Models\GeneratedContent;
use App\Models\Project;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

class CreateGeneratedContent
{
    public function __construct(private ContentTypeRegistry $contentTypes) {}

    public function handle(Project $project, string $contentType, ?string $title = null): GeneratedContent
    {
        if (! $project->exists || $project->getKey() === null) {
            throw new InvalidArgumentException('Generated content must belong to an existing project.');
        }

        $this->contentTypes->get($contentType);

        Validator::make(['title' => $title], [
            'title' => ['nullable', 'string', 'max:255'],
        ])->validate();

        return $project->generatedContents()->create([
            'content_type' => $contentType,
            'title' => $title,
        ]);
    }
}
