<?php

namespace App\Models;

use Database\Factories\ProjectContextFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'setting',
    'time_period',
    'locations',
    'people',
    'organizations',
    'canon_notes',
])]
class ProjectContext extends Model
{
    public const SECTION_FIELDS = [
        'setting',
        'time_period',
        'locations',
        'people',
        'organizations',
        'canon_notes',
    ];

    /** @use HasFactory<ProjectContextFactory> */
    use HasFactory;

    /**
     * @return array{setting: ?string, time_period: ?string, locations: ?string, people: ?string, organizations: ?string, canon_notes: ?string}
     */
    public function generationFields(): array
    {
        return [
            'setting' => $this->setting,
            'time_period' => $this->time_period,
            'locations' => $this->locations,
            'people' => $this->people,
            'organizations' => $this->organizations,
            'canon_notes' => $this->canon_notes,
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
