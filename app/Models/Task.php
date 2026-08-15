<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Task extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id', 'assigned_to', 'created_by', 'title', 'description',
        'status', 'priority', 'required_skill', 'estimated_hours',
        'actual_hours', 'due_date', 'completed_at', 'notes',
    ];

    protected $casts = [
        'due_date'     => 'date',
        'completed_at' => 'date',
    ];

    public function project()    { return $this->belongsTo(Project::class); }
    public function assignee()   { return $this->belongsTo(User::class, 'assigned_to'); }
    public function creator()    { return $this->belongsTo(User::class, 'created_by'); }

    public function getStatusArabicAttribute(): string
    {
        return match($this->status) {
            'pending'     => 'معلقة',
            'in_progress' => 'جارية',
            'completed'   => 'مكتملة',
            'cancelled'   => 'ملغاة',
            default       => $this->status,
        };
    }
}