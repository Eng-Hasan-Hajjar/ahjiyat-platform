<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectFinance extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'recorded_by',
        'entry_type',
        'category',
        'amount',
        'currency',
        'title',
        'description',
        'donor_name',
        'donor_anonymous',
        'attachment_path',
        'entry_date',
        'status',
        'verified_by',
        'verified_at',
        'rejection_reason',
    ];

    protected $casts = [
        'amount'          => 'decimal:2',
        'donor_anonymous' => 'boolean',
        'entry_date'      => 'date',
        'verified_at'     => 'datetime',
    ];

    // ─── Relationships ──────────────────────────────────────
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    // ─── Accessors (Arabic labels for views) ───────────────
    public function getEntryTypeArabicAttribute(): string
    {
        return match ($this->entry_type) {
            'donation' => 'تبرع',
            'expense'  => 'مصروف',
            default    => $this->entry_type,
        };
    }

    public function getCategoryArabicAttribute(): string
    {
        return match ($this->category) {
            'cash'                  => 'تبرع نقدي',
            'in_kind'                => 'تبرع عيني',
            'volunteer_hours_value' => 'قيمة ساعات تطوع',
            'materials'              => 'مواد بناء',
            'labor'                  => 'أجور عمالة',
            'equipment'              => 'معدات',
            'transport'              => 'نقل ومواصلات',
            'other'                  => 'أخرى',
            default                  => $this->category,
        };
    }

    public function getStatusArabicAttribute(): string
    {
        return match ($this->status) {
            'pending_review' => 'بانتظار المراجعة',
            'verified'        => 'موثّق ومعتمد',
            'rejected'        => 'مرفوض',
            default           => $this->status,
        };
    }

    public function getDonorDisplayNameAttribute(): string
    {
        if ($this->entry_type !== 'donation') {
            return '-';
        }
        return $this->donor_anonymous ? 'متبرع مجهول' : ($this->donor_name ?: 'غير محدد');
    }

    // ─── Scopes ─────────────────────────────────────────────
    public function scopeDonations($query)
    {
        return $query->where('entry_type', 'donation');
    }

    public function scopeExpenses($query)
    {
        return $query->where('entry_type', 'expense');
    }

    public function scopeVerified($query)
    {
        return $query->where('status', 'verified');
    }
}