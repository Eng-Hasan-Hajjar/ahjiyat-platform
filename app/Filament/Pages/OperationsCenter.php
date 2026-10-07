<?php

namespace App\Filament\Pages;

use App\Models\FraudFlag;
use App\Models\OperationalAuditLog;
use App\Models\RedemptionRequest;
use App\Models\User;
use Filament\Pages\Page;

/**
 * "ما الذي يحتاج تدخل Admin الآن؟" - ليست Analytics، فقط طوابير إجراءات
 * ونشاط حديث. كل قسم يحترم صلاحيته الخاصة.
 */
class OperationsCenter extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-command-line';

    protected static ?string $navigationGroup = 'إدارة الوصول';

    protected static ?string $navigationLabel = 'مركز العمليات';

    protected static ?string $slug = 'operations';

    protected static string $view = 'filament.pages.operations-center';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('operations.dashboard_view') ?? false;
    }

    // اكتشاف مهم: canAccess() وحدها تتحكم فقط بظهور العنصر بالقائمة
    // الجانبية - لا تمنع فتح الرابط مباشرة لمن يملك admin.access لكن
    // ليس operations.dashboard_view تحديداً (كانت تُرجع 302 بدل 403).
    // abort_unless صريح هنا يضمن حجباً حقيقياً على مستوى HTTP.
    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    public function getPendingRedemptionsCount(): ?int
    {
        return auth()->user()?->can('redemptions.view')
            ? RedemptionRequest::where('status', RedemptionRequest::STATUS_PENDING)->count()
            : null;
    }

    /** E10 (بند 138): طلبات شراء متجر يدوية بانتظار إنجاز الإدارة. */
    public function getPendingStoreFulfillmentCount(): ?int
    {
        return auth()->user()?->can('store.purchases.view')
            ? \App\Models\StorePurchase::where('status', \App\Models\StorePurchase::STATUS_PENDING_FULFILLMENT)->count()
            : null;
    }

    /** E20: مباريات فرق مقبولة تجاوزت مهلتها بلا اعتماد، وبطولات انتهت بلا اعتماد (يعالجها الأمر الدوري؛ ظهورها هنا يعني تأخره). */
    public function getOverdueTeamCompetitionCount(): ?int
    {
        $user = auth()->user();

        if (! ($user?->can('team_challenges.view') || $user?->can('team_championships.view'))) {
            return null;
        }

        return \App\Models\TeamChallenge::query()->where('status', 'accepted')->where('play_ends_at', '<=', now()->subHour())->count()
            + \App\Models\TeamChampionship::query()->where('status', 'published')->where('ends_at', '<=', now()->subHour())->count();
    }

    /** E18: منح جوائز تنافسية فاشلة (تحتاج إعادة محاولة بالإدارة). */
    public function getFailedCompetitiveGrantsCount(): ?int
    {
        return auth()->user()?->can('competitive_events.rewards.view')
            ? \App\Models\CompetitiveRewardGrant::query()->where('status', \App\Models\CompetitiveRewardGrant::STATUS_FAILED)->count()
            : null;
    }

    public function getOpenFraudFlagsCount(): ?int
    {
        return auth()->user()?->can('fraud.view')
            ? FraudFlag::where('resolved', false)->count()
            : null;
    }

    public function getFrozenUsersCount(): ?int
    {
        return auth()->user()?->can('users.view')
            ? User::where('is_frozen', true)->count()
            : null;
    }

    public function getUnverifiedUsersCount(): ?int
    {
        return auth()->user()?->can('users.view')
            ? User::whereNull('email_verified_at')->count()
            : null;
    }

    public function getRecentUsers()
    {
        return auth()->user()?->can('users.view')
            ? User::latest()->limit(8)->get(['id', 'name', 'email', 'created_at'])
            : collect();
    }

    public function getRecentFraudFlags()
    {
        return auth()->user()?->can('fraud.view')
            ? FraudFlag::with('user:id,name')->where('resolved', false)->latest()->limit(8)->get()
            : collect();
    }

    public function getRecentOperationalActions()
    {
        return auth()->user()?->can('operations.dashboard_view')
            ? OperationalAuditLog::with('actor:id,name')->latest()->limit(10)->get()
            : collect();
    }

    protected static array $actionLabels = [
        'user_frozen' => 'تجميد حساب',
        'user_unfrozen' => 'رفع تجميد حساب',
        'wallet_manual_adjustment' => 'تعديل جواهر يدوي',
        'redemption_approved' => 'قبول طلب استبدال',
        'redemption_rejected' => 'رفض طلب استبدال',
        'redemption_fulfilled' => 'تنفيذ طلب استبدال',
        'fraud_flag_resolved' => 'معالجة إشارة أمنية',
        'session_revoked' => 'إنهاء جلسة',
        'all_sessions_revoked' => 'إنهاء كل الجلسات',
        'store_purchase_fulfilled' => 'إنجاز طلب شراء متجر',
        'store_purchase_refunded' => 'استرجاع شراء متجر',
    ];

    public function actionLabel(string $action): string
    {
        return static::$actionLabels[$action] ?? $action;
    }
}