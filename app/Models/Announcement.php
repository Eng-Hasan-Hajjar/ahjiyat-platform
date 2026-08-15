<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Announcement extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'title', 'content', 'target', 'is_published'];

    protected $casts = ['is_published' => 'boolean'];

    public function author() { return $this->belongsTo(User::class, 'user_id'); }

    public function getTargetArabicAttribute(): string
    {
        return match($this->target) {
            'all'        => 'الجميع',
            'volunteers' => 'المتطوعون فقط',
            'owners'     => 'أصحاب المشاريع فقط',
            default      => $this->target,
        };
    }
}