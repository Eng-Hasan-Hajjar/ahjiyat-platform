<?php

require_once __DIR__.'/SocialTestHelpers.php';

use App\Services\Social\BlockService;

test('40-44: request, accept, decline, cancel, remove and block grant NO XP, currency, quest progress, streak, achievement or any balance change', function () {
    [$a, $b, $c, $d] = [e16User(), e16User(), e16User(), e16User()];
    $before = e16Snapshot();

    $this->actingAs($a)->post(route('friends.requests.store', $b))->assertSessionHas('success');       // طلب
    $this->actingAs($b)->post(route('friends.requests.accept', $a))->assertSessionHas('success');      // قبول
    $this->actingAs($a)->delete(route('friends.remove', $b))->assertSessionHas('success');             // إزالة
    $this->actingAs($a)->post(route('friends.requests.store', $c))->assertSessionHas('success');
    $this->actingAs($c)->post(route('friends.requests.decline', $a))->assertSessionHas('success');     // رفض
    $this->actingAs($a)->post(route('friends.requests.store', $d))->assertSessionHas('success');
    $this->actingAs($a)->delete(route('friends.requests.cancel', $d))->assertSessionHas('success');    // إلغاء
    $this->actingAs($a)->post(route('friends.blocks.store', $b))->assertSessionHas('success');         // حظر
    $this->actingAs($a)->delete(route('friends.blocks.destroy', $b))->assertSessionHas('success');     // إلغاء حظر

    expect(e16Snapshot())->toBe($before);
});

test('opening and reading social notifications grants nothing and mutates no gameplay state', function () {
    [$a, $b] = [e16User(), e16User()];
    e16Svc()->sendRequest($a, $b);
    e16Svc()->accept($b, $a);
    $before = e16Snapshot();

    foreach ([[$b, 'friend_request_received'], [$a, 'friend_request_accepted']] as [$user, $type]) {
        $note = e16Notes($type, $user)->sole();
        $this->actingAs($user)->post(route('notifications.open', $note->id))->assertRedirect(route('friends.index'));
        expect($note->fresh()->read_at)->not->toBeNull();
    }
    $this->actingAs($b)->post(route('notifications.read-all'));

    expect(e16Snapshot())->toBe($before);
});

test('the social domain has no dependency on any reward, progression, wallet, quest or streak service (static audit)', function () {
    $files = array_merge(glob(app_path('Services/Social/*.php')), glob(app_path('Listeners/SendFriend*.php')), glob(app_path('Http/Controllers/Friend*.php')));
    $forbidden = ['XpService', 'CurrencyWalletService', 'WalletService', 'QuestService', 'StreakService', 'AchievementService', 'ProgressionService', 'StoreService', 'RewardService', 'EntitlementService'];

    foreach ($files as $file) {
        $code = file_get_contents($file);

        foreach ($forbidden as $class) {
            expect(str_contains($code, $class))->toBeFalse(basename($file)." must not depend on {$class}");
        }
    }
});
