<?php

use App\Models\RedemptionRequest;
use App\Models\User;
use App\Models\Wallet;
use App\Services\RedemptionService;
use Illuminate\Support\Facades\Gate;

beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
});

test('redemptions.view/approve/reject permissions are enforced via the policy', function () {
    $approver = User::factory()->create();
    $approver->assignRole('administrator');

    $viewer = User::factory()->create();
    $viewer->assignRole('support');

    $request = RedemptionRequest::factory()->create(['status' => RedemptionRequest::STATUS_PENDING]);

    expect(Gate::forUser($approver)->allows('approve', $request))->toBeTrue()
        ->and(Gate::forUser($approver)->allows('reject', $request))->toBeTrue()
        ->and(Gate::forUser($viewer)->allows('approve', $request))->toBeFalse()
        ->and(Gate::forUser($viewer)->allows('reject', $request))->toBeFalse();
});

test('rejecting a pending request refunds the gems exactly once', function () {
    $admin = User::factory()->create();
    $admin->assignRole('administrator');

    $user = User::factory()->create();
    Wallet::factory()->create(['user_id' => $user->id, 'available_balance' => 0]);

    $request = RedemptionRequest::factory()->create([
        'user_id' => $user->id, 'status' => RedemptionRequest::STATUS_PENDING, 'gems_amount' => 100,
    ]);

    app(RedemptionService::class)->reject($request, $admin, 'سبب الرفض');

    expect($user->wallet->fresh()->available_balance)->toBe(100)
        ->and($request->fresh()->status)->toBe(RedemptionRequest::STATUS_REJECTED);
});

test('approving a request does not deduct gems again - they were already deducted at request time', function () {
    $admin = User::factory()->create();
    $admin->assignRole('administrator');

    $user = User::factory()->create();
    Wallet::factory()->create(['user_id' => $user->id, 'available_balance' => 500]);

    $request = RedemptionRequest::factory()->create([
        'user_id' => $user->id, 'status' => RedemptionRequest::STATUS_PENDING, 'gems_amount' => 100,
    ]);

    app(RedemptionService::class)->approve($request, $admin, 'موافق');

    expect($user->wallet->fresh()->available_balance)->toBe(500)
        ->and($request->fresh()->status)->toBe(RedemptionRequest::STATUS_APPROVED)
        ->and($request->fresh()->reviewed_by)->toBe($admin->id);
});

test('a content-manager role cannot approve or reject redemptions', function () {
    $contentManager = User::factory()->create();
    $contentManager->assignRole('content-manager');

    $request = RedemptionRequest::factory()->create(['status' => RedemptionRequest::STATUS_PENDING]);

    expect(Gate::forUser($contentManager)->allows('approve', $request))->toBeFalse()
        ->and(Gate::forUser($contentManager)->allows('reject', $request))->toBeFalse();
});