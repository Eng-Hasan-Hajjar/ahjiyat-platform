<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectApproval extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'submitted_by',
        'document_path',
        'owner_national_id',
        'status',
        'reviewed_by',
        'reviewed_at',
        'review_notes',
    ];

    protected $casts = [
        'reviewed_at' => 'datetime',
    ];

    // ─── Relationships ──────────────────────────────────────
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    // ─── Accessors ──────────────────────────────────────────
    public function getStatusArabicAttribute(): string
    {
        return match ($this->status) {
            'pending_review' => 'بانتظار مراجعة المشرف',
            'approved'        => 'معتمدة رسمياً',
            'rejected'        => 'مرفوضة',
            default           => $this->status,
        };
    }

    public function getDocumentUrlAttribute(): string
    {
        return asset('storage/' . $this->document_path);
    }

    // إخفاء جزء من رقم الهوية الوطنية لأغراض الخصوصية عند العرض العام
    public function getMaskedNationalIdAttribute(): ?string
    {
        if (!$this->owner_national_id) {
            return null;
        }
        $id = $this->owner_national_id;
        $len = strlen($id);
        if ($len <= 4) {
            return str_repeat('*', $len);
        }
        return str_repeat('*', $len - 4) . substr($id, -4);
    }
}