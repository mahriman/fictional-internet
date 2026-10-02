<?php

namespace App\Models;

use App\Enums\GeneratedContentVersionOrigin;
use Database\Factories\GeneratedContentVersionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

#[Fillable([
    'generated_content_id',
    'based_on_version_id',
    'version_number',
    'origin',
    'content',
    'context_snapshot',
    'generation_metadata',
])]
class GeneratedContentVersion extends Model
{
    public const UPDATED_AT = null;

    /** @use HasFactory<GeneratedContentVersionFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::updating(static function (GeneratedContentVersion $version): never {
            throw new LogicException('Generated content versions are immutable.');
        });

        static::deleting(static function (GeneratedContentVersion $version): never {
            throw new LogicException('Generated content versions cannot be deleted individually.');
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version_number' => 'integer',
            'origin' => GeneratedContentVersionOrigin::class,
            'content' => 'array',
            'context_snapshot' => 'array',
            'generation_metadata' => 'array',
        ];
    }

    public function generatedContent(): BelongsTo
    {
        return $this->belongsTo(GeneratedContent::class);
    }

    public function basedOnVersion(): BelongsTo
    {
        return $this->belongsTo(self::class, 'based_on_version_id');
    }

    public function descendantVersions(): HasMany
    {
        return $this->hasMany(self::class, 'based_on_version_id');
    }
}
