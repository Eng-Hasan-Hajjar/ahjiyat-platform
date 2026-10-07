<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** مؤشر قراءة مستخدم بغرفة (E21): صف واحد، يُكتب بالخدمة بحماية رتابة (لا يتراجع). */
class ChatReadState extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['last_read_at' => 'datetime'];
    }
}
