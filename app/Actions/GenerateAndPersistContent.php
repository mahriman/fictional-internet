<?php

namespace App\Actions;

use App\ContentTypes\ContentTypeRegistry;
use App\Enums\GeneratedContentVersionOrigin;
use App\Models\Project;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class GenerateAndPersistContent
{
    public function __construct(
        private ContentTypeRegistry $contentTypes,
        private GenerateStructuredContent $generateContent,
        private CreateGeneratedContent $createContent,
        private AppendGeneratedContentVersion $appendVersion,
    ) {}

    /**
     * Invoke outside caller-owned database transactions so the provider request runs without an open transaction.
     */
    public function handle(
        Project $project,
        string $contentType,
        string $prompt,
        ?string $apiKey = null,
    ): GeneratedContentGenerationResult {
        if (! $project->exists || $project->getKey() === null) {
            throw new InvalidArgumentException('Generated content must belong to an existing project.');
        }

        $definition = $this->contentTypes->get($contentType);
        $generation = $this->generateContent->handle($contentType, $prompt, $apiKey);

        $contextSnapshot = [
            'content_type' => $contentType,
            'prompt' => $prompt,
            'instructions' => $definition->promptInstructions(),
        ];

        $generationMetadata = [
            'provider' => 'openai',
            'model' => $generation->response->model,
            'response_id' => $generation->response->responseId,
            'input_tokens' => $generation->response->inputTokens,
            'output_tokens' => $generation->response->outputTokens,
            'total_tokens' => $generation->response->totalTokens,
            'content_type' => $contentType,
        ];

        return DB::transaction(function () use (
            $project,
            $contentType,
            $definition,
            $generation,
            $contextSnapshot,
            $generationMetadata,
        ): GeneratedContentGenerationResult {
            $generatedContent = $this->createContent->handle(
                $project,
                $contentType,
                $definition->titleFromContent($generation->content),
            );

            $version = $this->appendVersion->handle(
                $generatedContent,
                $generation->content,
                GeneratedContentVersionOrigin::AiGenerated,
                $contextSnapshot,
                $generationMetadata,
            );

            return new GeneratedContentGenerationResult(
                generatedContent: $generatedContent,
                version: $version,
                generationMetadata: $generationMetadata,
            );
        });
    }
}
