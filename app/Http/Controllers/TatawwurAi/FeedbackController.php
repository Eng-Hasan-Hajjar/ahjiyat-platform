<?php

namespace App\Http\Controllers\TatawwurAi;

use App\Http\Controllers\Controller;
use App\Http\Requests\TatawwurAi\FeedbackRequest;
use App\Models\AiFeedback;
use App\Models\AiMessage;
use Illuminate\Http\JsonResponse;

/**
 * E45 - تقييم 👍/👎 لردّ مساعد يملكه العضو. نخزّن: الرسالة + التقييم + سببًا مغلقًا اختياريًا + نتيجة الردّ فقط (بلا نص). لا يغيّر سلوك النموذج تلقائيًا
 * (الإدارة تستعمله لتحسين المقالات). تقييم واحد لكل (رسالة، مستخدم): التقييم الجديد يستبدل القديم.
 */
class FeedbackController extends Controller
{
    public function store(FeedbackRequest $request): JsonResponse
    {
        $user = $request->user();

        $message = AiMessage::query()
            ->where('public_id', strtoupper((string) $request->validated('message')))
            ->where('role', AiMessage::ROLE_ASSISTANT)
            ->whereHas('conversation', fn ($q) => $q->where('user_id', $user->getKey()))
            ->first();

        abort_if($message === null, 404);

        $rating = (string) $request->validated('rating');
        $feedback = AiFeedback::query()->where('message_id', $message->getKey())->where('user_id', $user->getKey())->first() ?? new AiFeedback;
        $feedback->forceFill([
            'message_id' => $message->getKey(),
            'user_id' => $user->getKey(),
            'rating' => $rating,
            'reason' => $rating === AiFeedback::RATING_DOWN ? $request->validated('reason') : null,
            'outcome' => $message->outcome,
        ])->save();

        return response()->json(['saved' => true, 'rating' => $rating]);
    }
}
