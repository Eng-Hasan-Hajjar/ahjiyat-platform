<?php

namespace App\Services\TrustSafety;

use App\Models\ChatMessage;
use App\Models\CreatorProfile;
use App\Models\CreatorPuzzle;
use App\Models\DiscussionComment;
use App\Models\User;
use App\Models\UserCollection;

/**
 * E38 - يحلّ مواضيع الحالات/الصندوق إلى (تسمية، مالك، مقتطف) **بدُفعات** (استعلام لكل نوع، لا N+1). قراءة فقط، للمشرفين فقط.
 * مقتطف الدردشة لا يُجلب إلا لرسالة **مُبلَّغ عنها** (الصندوق يبدأ من بلاغ دائمًا): لا تصفح لمحادثات خاصة.
 *
 * @phpstan-type Subject array{type:string,id:int,exists:bool,label:string,owner_id:?int,excerpt:?string}
 */
final class SubjectResolver
{
    /**
     * @param  iterable<array{0:string,1:int}>  $pairs  (subject_type, subject_id)
     * @return array<string, array{type:string,id:int,exists:bool,label:string,owner_id:?int,excerpt:?string}> key = "type:id"
     */
    public function resolve(iterable $pairs): array
    {
        $byType = [];

        foreach ($pairs as [$type, $id]) {
            $byType[$type][(int) $id] = (int) $id;
        }

        $out = [];

        foreach ($byType as $type => $ids) {
            $ids = array_values($ids);

            $found = match ($type) {
                'community_puzzle' => $this->puzzles($ids),
                'user_collection' => $this->collections($ids),
                'discussion_comment' => $this->comments($ids),
                'chat_message' => $this->messages($ids),
                'user' => $this->users($ids),
                default => [],
            };

            foreach ($ids as $id) {
                $out[$type.':'.$id] = $found[$id] ?? ['type' => $type, 'id' => $id, 'exists' => false, 'label' => 'محتوى محذوف أو غير متاح', 'owner_id' => null, 'excerpt' => null];
            }
        }

        return $out;
    }

    /** @return array{type:string,id:int,exists:bool,label:string,owner_id:?int,excerpt:?string} */
    public function one(string $type, int $id): array
    {
        return $this->resolve([[$type, $id]])[$type.':'.$id];
    }

    /** @param list<int> $ids */
    protected function puzzles(array $ids): array
    {
        $works = CreatorPuzzle::query()->whereIn('id', $ids)->get(['id', 'published_title', 'creator_profile_id']);
        $owners = CreatorProfile::query()->whereIn('id', $works->pluck('creator_profile_id')->unique()->all())->pluck('user_id', 'id');
        $out = [];

        foreach ($works as $work) {
            $out[(int) $work->id] = [
                'type' => 'community_puzzle', 'id' => (int) $work->id, 'exists' => true,
                'label' => 'أحجية مجتمع: '.PlainText::excerpt((string) ($work->published_title ?: 'عمل #'.$work->id), 100),
                'owner_id' => ($owners[$work->creator_profile_id] ?? null) !== null ? (int) $owners[$work->creator_profile_id] : null,
                'excerpt' => null,
            ];
        }

        return $out;
    }

    /** @param list<int> $ids */
    protected function collections(array $ids): array
    {
        $out = [];

        foreach (UserCollection::query()->whereIn('id', $ids)->get(['id', 'user_id', 'title', 'description']) as $collection) {
            $out[(int) $collection->id] = [
                'type' => 'user_collection', 'id' => (int) $collection->id, 'exists' => true,
                'label' => 'مجموعة: '.PlainText::excerpt((string) $collection->title, 100),
                'owner_id' => (int) $collection->user_id,
                'excerpt' => PlainText::excerpt((string) $collection->description, (int) config('trust_safety.case.excerpt_max')),
            ];
        }

        return $out;
    }

    /** @param list<int> $ids */
    protected function comments(array $ids): array
    {
        $out = [];

        foreach (DiscussionComment::query()->whereIn('id', $ids)->get(['id', 'user_id', 'body']) as $comment) {
            $out[(int) $comment->id] = [
                'type' => 'discussion_comment', 'id' => (int) $comment->id, 'exists' => true, 'label' => 'تعليق نقاش',
                'owner_id' => $comment->user_id !== null ? (int) $comment->user_id : null,
                'excerpt' => PlainText::excerpt((string) $comment->body, (int) config('trust_safety.case.excerpt_max')),
            ];
        }

        return $out;
    }

    /** @param list<int> $ids */
    protected function messages(array $ids): array
    {
        $out = [];

        foreach (ChatMessage::query()->whereIn('id', $ids)->get(['id', 'sender_id', 'body']) as $message) {
            $out[(int) $message->id] = [
                'type' => 'chat_message', 'id' => (int) $message->id, 'exists' => true, 'label' => 'رسالة دردشة مُبلَّغ عنها',
                'owner_id' => $message->sender_id !== null ? (int) $message->sender_id : null,
                'excerpt' => PlainText::excerpt((string) $message->body, (int) config('trust_safety.case.excerpt_max')),
            ];
        }

        return $out;
    }

    /** @param list<int> $ids */
    protected function users(array $ids): array
    {
        $out = [];

        foreach (User::query()->whereIn('id', $ids)->get(['id', 'name']) as $user) {
            $out[(int) $user->id] = [
                'type' => 'user', 'id' => (int) $user->id, 'exists' => true,
                'label' => 'مستخدم: '.PlainText::excerpt((string) $user->name, 80),
                'owner_id' => (int) $user->id, 'excerpt' => null,
            ];
        }

        return $out;
    }
}
