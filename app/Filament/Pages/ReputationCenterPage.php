<?php

namespace App\Filament\Pages;

use App\Models\CommunityFeaturedCreator;
use App\Models\CommunityReputationSnapshot;
use App\Models\CreatorProfile;
use App\Models\User;
use App\Services\OperationalAuditService;
use App\Services\Reputation\CommunityReputationService;
use App\Services\Reputation\FeaturedCreatorService;
use App\Services\Reputation\ReputationAnalytics;
use App\Services\Reputation\ReputationExplainer;
use App\Services\Reputation\ReputationRankings;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * E37 - مركز السمعة للإدارة. عرض أرقام مجمَّعة وشرح درجات مستخدم، مع إجراءين فقط:
 *  - إعادة حساب لقطة مستخدم (reputation.manage): يعيد اشتقاقها من سجلات السلطة ويُدقَّق؛ لا يكتب أي درجة بيد بشر.
 *  - اختيار/إنهاء «مبدع مختار» (reputation.feature): إبراز تحريري مُدقَّق عبر FeaturedCreatorService (الصلاحية والتدقيق والسقف هناك).
 * لا حقل لتعديل درجة ولا اعتراف ولا ترتيب، ولا يظهر هنا أي علم احتيال أو سجل إشراف أو بريد. البحث بـslug مبدع أو المعرّف العام للاعب.
 */
class ReputationCenterPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-star';

    protected static ?string $navigationGroup = 'المشاركة';

    protected static ?string $navigationLabel = 'مركز السمعة';

    protected static ?string $title = 'مركز السمعة المجتمعية';

    protected static ?string $slug = 'reputation-center';

    protected static ?int $navigationSort = 72;

    protected static string $view = 'filament.pages.reputation-center';

    public string $lookup = '';

    public string $note = '';

    /** المعرّف العام للمستخدم قيد الفحص (مفتاح بحث فقط؛ كل إجراء يعيد الفحص والتفويض خادميًا). */
    public ?string $inspect = null;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('reputation.view') ?? false;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    /** @return array<string, mixed> */
    public function data(): array
    {
        return app(ReputationAnalytics::class)->admin();
    }

    public function search(): void
    {
        abort_unless(static::canAccess(), 403);

        $term = trim($this->lookup);
        $user = null;

        if ($term !== '') {
            $profile = CreatorProfile::query()->where('slug', $term)->first();
            $user = $profile !== null ? User::query()->find($profile->user_id) : User::query()->where('public_id', $term)->first();
        }

        if ($user === null) {
            $this->inspect = null;
            Notification::make()->title('لا نتيجة: أدخل slug مبدع أو المعرّف العام للاعب.')->warning()->send();

            return;
        }

        $this->inspect = (string) $user->public_id;
    }

    /** @return array<string, mixed>|null */
    public function inspected(): ?array
    {
        $user = $this->inspectedUser();

        if ($user === null) {
            return null;
        }

        $profile = CreatorProfile::query()->where('user_id', $user->id)->first();

        return [
            'name' => $user->name,
            'public_id' => $user->public_id,
            'creator_slug' => $profile?->slug,
            'creator_status' => $profile?->status,
            'featured' => $profile !== null && app(ReputationRankings::class)->isFeatured((int) $profile->id),
            'explain' => app(ReputationExplainer::class)->forSnapshot(CommunityReputationSnapshot::query()->where('user_id', $user->id)->first()),
        ];
    }

    public function recalculate(): void
    {
        abort_unless(auth()->user()?->can('reputation.manage'), 403);

        $user = $this->inspectedUser();

        if ($user === null) {
            return;
        }

        app(CommunityReputationService::class)->refreshUser((int) $user->id);
        app(ReputationRankings::class)->flush();
        app(OperationalAuditService::class)->log('reputation_recalculated', $user, ['user' => $user->public_id], auth()->user());

        Notification::make()->title('أُعيد حساب لقطة السمعة من السجلات الأصلية.')->success()->send();
    }

    public function feature(): void
    {
        $user = $this->inspectedUser();
        $profile = $user !== null ? CreatorProfile::query()->where('user_id', $user->id)->first() : null;

        if ($profile === null) {
            Notification::make()->title('هذا المستخدم ليس مبدعًا.')->warning()->send();

            return;
        }

        try {
            app(FeaturedCreatorService::class)->feature($profile, auth()->user(), $this->note !== '' ? $this->note : null);
            $this->note = '';
            Notification::make()->title('أصبح المبدع «مختارًا».')->success()->send();
        } catch (\App\Services\Engagement\EngagementException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        }
    }

    public function unfeature(string $slug): void
    {
        $profile = CreatorProfile::query()->where('slug', $slug)->first();

        if ($profile === null) {
            return;
        }

        try {
            app(FeaturedCreatorService::class)->unfeature($profile, auth()->user());
            Notification::make()->title('أُنهي إبراز المبدع.')->success()->send();
        } catch (\App\Services\Engagement\EngagementException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        }
    }

    /** @return list<array{slug: string, name: string, note: ?string, until: ?string}> */
    public function featuredList(): array
    {
        return CommunityFeaturedCreator::query()->activeNow()
            ->join('creator_profiles as p', 'p.id', '=', 'community_featured_creators.creator_profile_id')
            ->join('users as u', 'u.id', '=', 'p.user_id')
            ->orderByDesc('community_featured_creators.featured_from')->limit(50)
            ->get(['p.slug as slug', 'u.name as name', 'community_featured_creators.note as note', 'community_featured_creators.featured_until as until'])
            ->map(fn ($r) => ['slug' => (string) $r->slug, 'name' => (string) $r->name, 'note' => $r->note, 'until' => $r->until])->all();
    }

    protected function inspectedUser(): ?User
    {
        return $this->inspect !== null ? User::query()->where('public_id', $this->inspect)->first() : null;
    }
}
