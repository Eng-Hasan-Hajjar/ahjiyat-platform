<?php

require_once __DIR__.'/UiTestHelpers.php';

use App\Models\Role;
use App\Models\User;

beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
});

function uiSuperAdmin(): User
{
    $admin = User::factory()->create();
    $admin->assignRole('super-admin');

    return $admin;
}

const UI_ADMIN_GROUP_ORDER = [
    'الأحجيات', 'الحملات', 'المنافسات', 'الفرق', 'المتجر', 'الاقتصاد', 'الجواهر والاستبدال', 'التقدُّم',
    'المشاركة', 'الإعلانات والرعايات', 'الإشراف', 'الأمان', 'التحليلات والنظام', 'إدارة الوصول',
];

test('A1: the admin sidebar lists its navigation groups in the logical E22 order', function () {
    $html = $this->actingAs(uiSuperAdmin())->get('/admin')->assertOk()->getContent();
    preg_match_all('/data-group-label="([^"]+)"/u', $html, $m);

    expect($m[1])->toBe(UI_ADMIN_GROUP_ORDER);
});

test('A2: the analytics and operations centers render in their new group, and ordinary players are refused', function () {
    $admin = uiSuperAdmin();

    foreach (['/admin/analytics', '/admin/operations'] as $url) {
        $this->actingAs($admin)->get($url)->assertOk();
    }

    $player = User::factory()->create();
    $player->assignRole('player');

    foreach (['/admin/analytics', '/admin/operations', '/admin/chat-message-reports', '/admin/teams'] as $url) {
        $this->actingAs($player)->get($url)->assertForbidden();
    }
});

test('A3: regrouped resources keep their permissions and only change their navigation group', function () {
    expect(\App\Filament\Resources\ChatMessageReportResource::getNavigationGroup())->toBe('الإشراف')
        ->and(\App\Filament\Resources\ChatMuteResource::getNavigationGroup())->toBe('الإشراف')
        ->and(\App\Filament\Resources\TeamResource::getNavigationGroup())->toBe('الفرق')
        ->and(\App\Filament\Resources\TeamChallengeResource::getNavigationGroup())->toBe('الفرق')
        ->and(\App\Filament\Resources\TeamChampionshipResource::getNavigationGroup())->toBe('الفرق')
        ->and(\App\Filament\Resources\CompetitiveEventResource::getNavigationGroup())->toBe('المنافسات')
        ->and(\App\Filament\Pages\AnalyticsCenter::getNavigationGroup())->toBe('التحليلات والنظام')
        ->and(\App\Filament\Pages\OperationsCenter::getNavigationGroup())->toBe('التحليلات والنظام');

    $role = Role::create(['name' => 'bare-entry', 'guard_name' => 'web']);
    $role->givePermissionTo('admin.access');
    $bare = User::factory()->create();
    $bare->assignRole('bare-entry');

    $this->actingAs($bare)->get('/admin')->assertOk();

    foreach (['/admin/chat-message-reports', '/admin/teams', '/admin/competitive-events', '/admin/analytics', '/admin/operations'] as $url) {
        $this->actingAs($bare)->get($url)->assertForbidden();
    }
});
