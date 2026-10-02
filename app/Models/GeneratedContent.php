<?php

namespace App\Models;

use Database\Factories\GeneratedContentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['project_id', 'content_type', 'title'])]
class GeneratedContent extends Model
{
    /** @use HasFactory<GeneratedContentFactory> */
    use HasFactory;

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(GeneratedContentVersion::class)->orderBy('version_number');
    }
}
