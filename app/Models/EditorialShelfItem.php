<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** E35 - مرجع محتوى داخل رف تحريري: (نوع من قائمة مغلقة، معرّف رقمي) + موضع صريح. لا نص ولا رابط ولا اسم صنف. تكتبه EditorialShelfService وحدها. */
class EditorialShelfItem extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['content_id' => 'integer', 'position' => 'integer'];
    }

    public function shelf(): BelongsTo
    {
        return $this->belongsTo(EditorialShelf::class, 'editorial_shelf_id');
    }
}
