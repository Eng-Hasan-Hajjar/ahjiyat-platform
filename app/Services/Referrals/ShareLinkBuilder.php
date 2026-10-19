<?php

namespace App\Services\Referrals;

use App\Models\User;

/**
 * E33 (C) - منشئ روابط المشاركة الموحَّد. كل الروابط من أسماء مسارات مسماة (`route()`) على المضيف المضبوط للتطبيق: لا URL يأتي من العميل ولا من الطلب
 * (فلا open redirect ولا حقن). القائمة المغلقة للأنواع في config(referrals.share_kinds).
 *
 * الرابط الأساسي (url) نظيف بلا معاملات = الرابط القانوني (canonical). النسخة «مع دعوتي» (url_ref) تُضاف فقط لمستخدم لديه رمز نشط، وتحمل ?ref=CODE
 * (+ &c=slug لحملة حيّة مرتبطة بالموسم). لا إنشاء رمز هنا (قراءة فقط) فلا كتابة على GET.
 * أحجيات/محتوى المجتمع تُشارَك كروابط عادية؛ المشاركة لا تمنح اقتصادًا ولا تغيّر ترتيبًا.
 */
class ShareLinkBuilder
{
    public const ROUTES = [
        'puzzle' => 'puzzles.show',
        'community_puzzle' => 'community.puzzles.show',
        'series' => 'series.show',
        'creator' => 'creators.show',
        'season' => 'seasons.show',
        'campaign' => 'campaigns.show',
        'competition' => 'competitions.show',
        'supporters' => 'supporters.show',
        'platform' => 'home',
    ];

    public function __construct(protected ReferralCodeService $codes, protected ReferralCampaignService $campaigns) {}

    /**
     * @param  mixed  $routeParam  المعامل نفسه الذي تستعمله الواجهات في route() لهذه الصفحة (نموذج/slug/public_id)؛ لا قيمة من العميل.
     * @return array{kind: string, title: string, text: string, url: string, url_ref: ?string, links: array{plain: array<string, string>, ref: ?array<string, string>}}
     */
    public function build(string $kind, mixed $routeParam, string $title, ?string $text = null, ?User $viewer = null, ?int $seasonId = null): array
    {
        abort_unless(isset(self::ROUTES[$kind]) && in_array($kind, (array) config('referrals.share_kinds'), true), 404);

        $url = match (true) {
            $kind === 'platform' => route('home'),
            $kind === 'supporters' && $routeParam === null => route('supporters.index'),
            default => route(self::ROUTES[$kind], $routeParam),
        };
        $title = mb_substr(trim($title), 0, 120);
        $text = mb_substr(trim($text ?? $title), 0, 200);

        $urlRef = null;
        $code = $viewer !== null && $viewer->email_verified_at !== null && ! $viewer->is_frozen ? $this->codes->myCode($viewer) : null;

        if ($code !== null) {
            $query = ['ref' => $code];

            if ($kind === 'season' && $seasonId !== null && ($slug = $this->campaigns->slugForSeason($seasonId)) !== null) {
                $query['c'] = $slug;
            }

            $urlRef = $url.'?'.http_build_query($query);
        }

        return [
            'kind' => $kind, 'title' => $title, 'text' => $text, 'url' => $url, 'url_ref' => $urlRef,
            'links' => ['plain' => $this->targets($title, $url), 'ref' => $urlRef === null ? null : $this->targets($title, $urlRef)],
        ];
    }

    /** @return array<string, string> */
    protected function targets(string $title, string $url): array
    {
        return [
            'whatsapp' => 'https://wa.me/?text='.rawurlencode($title."\n".$url),
            'telegram' => 'https://t.me/share/url?url='.rawurlencode($url).'&text='.rawurlencode($title),
        ];
    }
}
