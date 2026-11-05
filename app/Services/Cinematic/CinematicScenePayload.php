<?php

namespace App\Services\Cinematic;

use App\Models\Cinematic\CinematicMediaAsset;
use App\Models\Cinematic\CinematicScene;
use App\Models\User;
use App\Services\Cinematic\Interactions\InteractionEngine;
use App\Services\Cinematic\Media\CinematicMediaLinks;
use App\Services\Cinematic\Media\CinematicMediaService;

/**
 * CIN-1 - ما يُرسل للمتصفح عن مشهد: **قائمة بيضاء صريحة** (لا toArray على الموديل أبدًا). لا حل ولا إجابة أحجية ولا مسار تخزين ولا معرّف داخلي.
 * روابط الوسائط موقَّعة قصيرة العمر ومربوطة بالمستخدم. وسيط غير جاهز يظهر كحالة (awaiting/missing/unavailable) بدل خطأ، فيبقى النص البديل متاحًا.
 */
class CinematicScenePayload
{
    public function __construct(
        protected InteractionEngine $engine,
        protected CinematicPuzzleBridge $puzzles,
        protected CinematicMediaLinks $links,
        protected CinematicMediaService $media,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function make(CinematicScene $scene, User $user, string $status, bool $preview = false): array
    {
        $config = (array) $scene->config;
        $payload = [
            'id' => $scene->public_id,
            'key' => $scene->stable_key,
            'type' => $scene->type,
            'title' => $scene->title,
            'status' => $status,
            'skippable' => (bool) $scene->is_skippable,
            'allow_replay' => (bool) $scene->allow_replay,
            'description' => (string) ($config['description'] ?? ''),
            'lines' => [],
            'intro_lines' => (array) ($config['intro_lines'] ?? []),
            'image' => null,
        ];

        // صورة المشهد (خلفية السرد/التفاعل): هي «الملصق» نفسه برابط موقَّع، فلا حقل تخزين إضافي ولا مسار عام.
        if ($scene->type !== CinematicScene::TYPE_VIDEO && $scene->poster !== null && $scene->poster->isReady() && $this->media->absolutePath($scene->poster) !== null) {
            $payload['image'] = $this->links->url($scene->poster, CinematicMediaLinks::SCOPE_SCENE, $scene->public_id, $user, $preview);
        }

        switch ($scene->type) {
            case CinematicScene::TYPE_VIDEO:
                $payload['transcript'] = (array) ($config['transcript'] ?? []);
                $payload['video'] = $this->video($scene, $user, $preview);
                break;

            case CinematicScene::TYPE_NARRATIVE:
                $payload['lines'] = (array) ($config['lines'] ?? []);
                break;

            case CinematicScene::TYPE_TRANSITION:
                $payload['transition'] = [
                    'title' => (string) ($config['title'] ?? ''),
                    'subtitle' => (string) ($config['subtitle'] ?? ''),
                    'next_stage_title' => (string) ($config['next_stage_title'] ?? ''),
                ];
                break;

            case CinematicScene::TYPE_PUZZLE:
                $payload['puzzle'] = $scene->puzzle ? $this->puzzles->publicView($scene->puzzle) : null;
                break;

            default:
                $public = (array) ($config['interaction'] ?? []);
                $kind = (string) ($public['kind'] ?? '');
                $payload['interaction'] = in_array($kind, InteractionEngine::KINDS, true) ? $this->engine->publicView($kind, $public) : null;
        }

        return $payload;
    }

    /** @return array<string, mixed> */
    protected function video(CinematicScene $scene, User $user, bool $preview): array
    {
        $asset = $scene->video;

        if ($asset === null) {
            return ['state' => $scene->awaiting_media ? 'awaiting' : 'missing', 'src' => null, 'poster' => null, 'subtitle' => null, 'audio' => null, 'duration' => $scene->duration_hint_seconds];
        }

        if (! $asset->isReady() || $this->media->absolutePath($asset) === null) {
            return ['state' => 'unavailable', 'src' => null, 'poster' => null, 'subtitle' => null, 'audio' => null, 'duration' => $scene->duration_hint_seconds];
        }

        $link = fn (?CinematicMediaAsset $a) => $a && $a->isReady() ? $this->links->url($a, CinematicMediaLinks::SCOPE_SCENE, $scene->public_id, $user, $preview) : null;

        return [
            'state' => 'ready',
            'src' => $link($asset),
            'poster' => $link($scene->poster),
            'subtitle' => $link($scene->subtitle),
            'audio' => $link($scene->audio),
            'duration' => $asset->duration_ms ? (int) round($asset->duration_ms / 1000) : $scene->duration_hint_seconds,
        ];
    }
}
