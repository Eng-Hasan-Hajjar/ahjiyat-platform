<?php

namespace App\Filament\Pages;

use App\Models\FraudFlag;
use App\Models\OperationalAuditLog;
use App\Models\RedemptionRequest;
use App\Models\User;
use Filament\Pages\Page;

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

    public function getPendingRedemptionsCount(): ?int
    {
        return auth()->user()?->can('redemptions.view')
            ? RedemptionRequest::where('status', RedemptionRequest::STATUS_PENDING)->count()
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
    ];

    public function actionLabel(string $action): string
    {
        return static::$actionLabels[$action] ?? $action;
    }
}