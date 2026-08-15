<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VolunteerApplication extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id', 'user_id', 'message', 'offered_skills',
        'available_hours_per_week', 'status', 'rejection_reason',
    ];

    protected $casts = ['offered_skills' => 'array'];

    public function project()   { return $this->belongsTo(Project::class); }
    public function volunteer() { return $this->belongsTo(User::class, 'user_id'); }

    public function getStatusArabicAttribute(): string
    {
        return match($this->status) {
            'pending'  => 'في الانتظار',
            'accepted' => 'مقبول',
            'rejected' => 'مرفوض',
            default    => $this->status,
        };
    }
}