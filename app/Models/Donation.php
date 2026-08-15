<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Donation extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id', 'donor_id', 'type', 'description', 'amount', 'status',
    ];

    public function project() { return $this->belongsTo(Project::class); }
    public function donor()   { return $this->belongsTo(User::class, 'donor_id'); }

    public function getTypeArabicAttribute(): string
    {
        return match($this->type) {
            'money'     => 'مال',
            'materials' => 'مواد بناء',
            'tools'     => 'أدوات',
            'food'      => 'طعام',
            default     => 'أخرى',
        };
    }
}