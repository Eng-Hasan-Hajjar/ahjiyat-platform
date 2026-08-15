<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectMedia extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'milestone_id',
        'uploaded_by',
        'media_type',
        'phase',
        'file_path',
        'caption',
    ];

    // ─── Relationships ──────────────────────────────────────
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function milestone(): BelongsTo
    {
        return $this->belongsTo(ProjectMilestone::class, 'milestone_id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    // ─── Accessors ──────────────────────────────────────────
    public function getUrlAttribute(): string
    {
        return asset('storage/' . $this->file_path);
    }

    public function getPhaseArabicAttribute(): string
    {
        return match ($this->phase) {
            'before' => 'قبل',
            'during' => 'أثناء العمل',
            'after'  => 'بعد',
            default  => $this->phase,
        };
    }

    public function getMediaTypeArabicAttribute(): string
    {
        return $this->media_type === 'video' ? 'فيديو' : 'صورة';
    }
}