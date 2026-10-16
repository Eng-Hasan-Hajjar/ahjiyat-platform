<?php

namespace App\Http\Requests\Creators;

use App\Services\Creators\CreatorPuzzleService;

/** النوع ثابت بعد الإنشاء: يُقرأ من آخر نسخة للعمل المملوك فعلًا (استعلام محصور بالمالك: عمل غيرك = 404 قبل أي تحقق). */
class UpdateCreatorPuzzleRequest extends CreatorContentRequest
{
    protected function kind(): string
    {
        $profile = $this->attributes->get('creator_profile');
        $work = app(CreatorPuzzleService::class)->ownedWork($profile, (string) $this->route('work'));

        return (string) $work->latestRevision()->value('kind');
    }
}
