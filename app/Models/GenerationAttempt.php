<?php

namespace App\Models;

use App\Enums\GenerationAttemptStatus;
use Database\Factories\GenerationAttemptFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['token_hash', 'user_id', 'project_id', 'status', 'claimed_at'])]
#[Hidden(['token_hash'])]
class GenerationAttempt extends Model
{
    /** @use HasFactory<GenerationAttemptFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => GenerationAttemptStatus::class,
            'claimed_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function generatedContent(): BelongsTo
    {
        return $this->belongsTo(GeneratedContent::class);
    }
}
