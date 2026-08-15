<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProjectUpdate extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id', 'user_id', 'title', 'description', 'images', 'progress_percentage',
    ];

    protected $casts = ['images' => 'array'];

    public function project() { return $this->belongsTo(Project::class); }
    public function author()  { return $this->belongsTo(User::class, 'user_id'); }
}