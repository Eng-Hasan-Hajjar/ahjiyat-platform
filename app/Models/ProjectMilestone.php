<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProjectMilestone extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'title',
        'description',
        'order_index',
        'status',
        'planned_date',
        'completed_date',
        'created_by',
    ];

    protected $casts = [
        'planned_date'   => 'date',
        'completed_date' => 'date',
    ];

    // ─── Relationships ──────────────────────────────────────
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function media(): HasMany
    {
        return $this->hasMany(ProjectMedia::class, 'milestone_id');
    }

    public function beforeMedia(): HasMany
    {
        return $this->media()->where('phase', 'before');
    }

    public function afterMedia(): HasMany
    {
        return $this->media()->where('phase', 'after');
    }

    // ─── Accessors ──────────────────────────────────────────
    public function getStatusArabicAttribute(): string
    {
        return match ($this->status) {
            'not_started' => 'لم تبدأ',
            'in_progress'  => 'قيد التنفيذ',
            'completed'    => 'مكتملة',
            default        => $this->status,
        };
    }
}