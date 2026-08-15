<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Rating extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id', 'rater_id', 'rated_user_id', 'type', 'rating', 'comment',
    ];

    public function project()   { return $this->belongsTo(Project::class); }
    public function rater()     { return $this->belongsTo(User::class, 'rater_id'); }
    public function ratedUser() { return $this->belongsTo(User::class, 'rated_user_id'); }
}