<?php

namespace App\Http\Controllers\Engagement\Concerns;

use App\Models\CreatorPuzzle;
use Illuminate\Http\RedirectResponse;

/**
 * E29 - حلّ العمل من معرّفه العام (ULID). الإضافة (تقييم/حفظ/مجموعة) تشترط عملًا **ظاهرًا** وإلا 404 كصفحته العامة تمامًا؛
 * الإزالة تقبل أي عمل موجود (ينظّف المستخدم قوائمه) لكن لا تكشف وجود عمل مخفي لمن لا صف له عليه (انظر المتحكمات).
 */
trait ResolvesCommunityWork
{
    protected function visibleWork(string $publicId): CreatorPuzzle
    {
        return CreatorPuzzle::query()->visible()->where('public_id', $publicId)->with('creator:id,user_id,slug')->firstOrFail();
    }

    protected function anyWork(string $publicId): CreatorPuzzle
    {
        return CreatorPuzzle::query()->where('public_id', $publicId)->with('creator:id,user_id,slug')->firstOrFail();
    }

    /** العودة لقسم التفاعل بصفحة الأحجية (يحفظ موضع القراءة على الجوال ويدعم التركيز لقارئات الشاشة). */
    protected function backToWork(CreatorPuzzle $work, ?string $status = null): RedirectResponse
    {
        $redirect = redirect()->to(route('community.puzzles.show', $work->public_id).'#engagement');

        return $status === null ? $redirect : $redirect->with('engagement_status', $status);
    }
}
