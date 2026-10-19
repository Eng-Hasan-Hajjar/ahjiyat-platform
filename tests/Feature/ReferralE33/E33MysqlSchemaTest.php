<?php

use App\Models\ReferralCampaign;
use App\Models\ReferralCode;
use App\Models\UserReferral;
use App\Services\Database\MysqlDdlCompiler;
use App\Services\Database\MysqlDdlLinter;
use App\Services\Referrals\ReferralException;
use App\Services\Referrals\ReferralRewardService;
use App\Services\Referrals\ReferralReviewService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

require_once __DIR__.'/_support.php';

/*
| AF11 - حدود السلامة في قاعدة البيانات نفسها. كل الاختبارات سائق-محايدة (SQLite افتراضيًا و MySQL 8 عبر phpunit.mysql.xml.dist)،
| فتثبت أن القيود لا تعتمد على كود التطبيق وحده، وأن ما يُختبر هنا هو نفسه ما سيعمل في الإنتاج على MySQL.
*/

beforeEach(fn () => e33_seed());

const E33_TABLES = ['referral_campaigns', 'referral_codes', 'user_referrals'];

function e33_row_referral(int $referredId, int $referrerId): array
{
    return ['public_id' => (string) Str::ulid(), 'referred_user_id' => $referredId, 'referrer_user_id' => $referrerId, 'created_at' => now(), 'updated_at' => now()];
}

function e33_row_code(int $userId, string $code, ?int $active): array
{
    return ['user_id' => $userId, 'code' => $code, 'active_for_user_id' => $active, 'created_at' => now(), 'updated_at' => now()];
}

test('AF11-70: one referrer per account is enforced by the database, and the loser of a race returns the winner without error or duplicate', function () {
    $referrer = e33_user();
    $other = e33_user();
    [$referred] = e33_attr($referrer);

    expect(fn () => DB::table('user_referrals')->insert(e33_row_referral($referred->id, $other->id)))->toThrow(QueryException::class)
        ->and(UserReferral::query()->where('referred_user_id', $referred->id)->count())->toBe(1);

    // سباق حقيقي: بعد أن يقرأ الخادم «لا إحالة» يتسلّل فائز آخر قبل الكتابة
    $racer = e33_user();
    $winnerReferrer = e33_user();
    $loserCode = e33_code($other);
    $injected = false;

    DB::listen(function ($query) use (&$injected, $racer, $winnerReferrer) {
        if (! $injected && str_starts_with(strtolower($query->sql), 'select') && str_contains($query->sql, 'user_referrals')) {
            $injected = true;
            DB::table('user_referrals')->insert(e33_row_referral($racer->id, $winnerReferrer->id));
        }
    });

    $result = app(\App\Services\Referrals\ReferralAttributionService::class)->attribute($racer, $loserCode);

    expect($injected)->toBeTrue()->and($result)->not->toBeNull()->and($result->referrer_user_id)->toBe($winnerReferrer->id)
        ->and(UserReferral::query()->where('referred_user_id', $racer->id)->count())->toBe(1)
        ->and(UserReferral::query()->where('referred_user_id', $racer->id)->value('referrer_user_id'))->toBe($winnerReferrer->id); // الأول يربح ولا يتبدّل
});

test('AF11-71: a code belongs to one person, a member has at most one active code, rotated codes stay as inactive history', function () {
    $user = e33_user();
    $other = e33_user();
    $first = e33_code($user);

    expect(fn () => DB::table('referral_codes')->insert(e33_row_code($other->id, $first, null)))->toThrow(QueryException::class)       // رمز مكرر
        ->and(fn () => DB::table('referral_codes')->insert(e33_row_code($user->id, 'ZZZZZZ', $user->id)))->toThrow(QueryException::class); // رمز نشط ثانٍ

    // عدة صفوف NULL مسموحة (سلوك UNIQUE على MySQL وSQLite)
    foreach (['QQQQQ2', 'QQQQQ3', 'QQQQQ4'] as $c) {
        DB::table('referral_codes')->insert(e33_row_code($user->id, $c, null));
    }
    expect(ReferralCode::query()->where('user_id', $user->id)->whereNull('active_for_user_id')->count())->toBe(3);

    $svc = e33_codes();
    $second = $svc->rotate($user);
    $third = $svc->rotate($user);

    expect(ReferralCode::query()->where('user_id', $user->id)->whereNotNull('active_for_user_id')->count())->toBe(1)
        ->and(ReferralCode::query()->where('user_id', $user->id)->count())->toBe(6)
        ->and($svc->activeFor($user)->code)->toBe($third->code)
        ->and($svc->findActive($first))->toBeNull()->and($svc->findActive($second->code))->toBeNull()->and($svc->findActive($third->code))->not->toBeNull();

    expect(fn () => $svc->rotate($user))->not->toThrow(ReferralException::class); // الثالثة ضمن الحد
    expect(fn () => $svc->rotate($user))->toThrow(ReferralException::class);       // الرابعة خلال 24 ساعة ممنوعة
});

test('AF11-72: the default slot and slug are unique, and every foreign key has the deletion policy the design promises', function () {
    $a = e33_campaign();
    $b = e33_campaign();
    $c = e33_campaign();
    expect(ReferralCampaign::query()->whereNull('default_slot')->count())->toBe(3); // عدة NULL متعايشة

    expect(fn () => DB::table('referral_campaigns')->where('id', $b->id)->update(['slug' => $a->slug]))->toThrow(QueryException::class);

    DB::table('referral_campaigns')->where('id', $a->id)->update(['default_slot' => 1]);
    expect(fn () => DB::table('referral_campaigns')->where('id', $b->id)->update(['default_slot' => 1]))->toThrow(QueryException::class);

    $expected = [
        'user_referrals' => ['referred_user_id' => 'cascade', 'referrer_user_id' => 'set null', 'referral_code_id' => 'set null', 'referral_campaign_id' => 'set null', 'reviewed_by' => 'set null'],
        'referral_codes' => ['user_id' => 'cascade'],
        'referral_campaigns' => ['season_id' => 'set null', 'created_by' => 'set null'],
    ];

    foreach ($expected as $table => $columns) {
        $actual = [];

        foreach (Schema::getForeignKeys($table) as $fk) {
            $actual[$fk['columns'][0]] = strtolower((string) $fk['on_delete']);
        }

        ksort($actual);
        ksort($columns);
        expect($actual)->toBe($columns);
    }

    // السلوك الفعلي لا الإعلان فقط
    $admin = e33_admin();
    $referrer = e33_user();
    $campaign = e33_campaign();
    [, $ref] = e33_attr($referrer, $campaign);
    app(ReferralReviewService::class)->disqualify($admin, $ref, 'other');
    $codeId = $ref->fresh()->referral_code_id;

    DB::table('referral_codes')->where('id', $codeId)->delete();
    DB::table('referral_campaigns')->where('id', $campaign->id)->delete();
    DB::table('users')->where('id', $admin->id)->delete();

    $after = $ref->fresh();
    expect($after->referral_code_id)->toBeNull()->and($after->referral_campaign_id)->toBeNull()->and($after->reviewed_by)->toBeNull()
        ->and($after->status)->toBe(UserReferral::STATUS_DISQUALIFIED)->and($after->referrer_user_id)->toBe($referrer->id);
});

test('AF11-73: the three new tables pass the MySQL DDL linter: short identifiers, integer gems, JSON only where intended, named unique guards', function () {
    $lint = (new MysqlDdlLinter)->analyze((new MysqlDdlCompiler)->compile()['statements']);
    $mine = array_intersect_key($lint['tables'], array_flip(E33_TABLES));

    expect(array_keys($mine))->toEqualCanonicalizing(E33_TABLES);

    $fails = array_values(array_filter($lint['findings'], fn (array $f) => $f['level'] === 'fail' && in_array($f['table'], E33_TABLES, true)));
    expect($fails)->toBe([])->and($lint['summary']['fail'])->toBe(0);

    foreach ($mine as $table => $model) {
        foreach ($model['columns'] as $name => $column) {
            expect(in_array($column['base'], ['float', 'double', 'real', 'decimal', 'numeric'], true))->toBeFalse("{$table}.{$name} must not be fractional");
            expect(strlen($name))->toBeLessThanOrEqual(64);
        }

        foreach (array_keys($model['indexes']) as $index) {
            expect(strlen($index))->toBeLessThanOrEqual(64);
        }

        foreach ($model['fks'] as $fk) {
            expect(strlen($fk['name']))->toBeLessThanOrEqual(64);
        }
    }

    $idx = fn (string $t, string $n) => $mine[$t]['indexes'][$n] ?? null;
    expect($idx('user_referrals', 'user_referrals_referred_user_unique'))->toMatchArray(['unique' => true, 'columns' => ['referred_user_id']])
        ->and($idx('referral_codes', 'referral_codes_active_for_user_unique'))->toMatchArray(['unique' => true, 'columns' => ['active_for_user_id']])
        ->and($idx('referral_campaigns', 'referral_campaigns_default_slot_unique'))->toMatchArray(['unique' => true, 'columns' => ['default_slot']]);

    foreach ([['user_referrals', 'snap_referrer_gems'], ['user_referrals', 'snap_referred_gems'], ['referral_campaigns', 'referrer_reward_gems'], ['referral_campaigns', 'referred_reward_gems'], ['referral_codes', 'clicks_count']] as [$t, $c]) {
        expect($mine[$t]['columns'][$c]['type'])->toContain('int')->toContain('unsigned');
    }

    expect($mine['user_referrals']['columns']['risk_signals']['base'])->toBe('json');

    // الاختبارات نفسها تعمل في CI على MySQL حقيقي (خطوة مستقلة قبل فحص المخطط الحيّ)
    $ci = preg_replace('/^\s*#.*$/m', '', File::get(base_path('.github/workflows/ci.yml')));
    expect($ci)->toContain('--configuration=phpunit.mysql.xml.dist tests/Feature/ReferralE33')
        ->and(strpos($ci, 'tests/Feature/ReferralE33'))->toBeLessThan(strpos($ci, 'db:parity --connection=mysql --runtime'));

    // نطاق الهجرات: ثلاث جديدة بالضبط بعد آخر ترحيل سابق، ولا لمسة لهجرة قديمة
    $new = collect(File::files(database_path('migrations')))->map->getFilename()->filter(fn ($f) => str_starts_with($f, '2026_10_26_'))->values();
    expect($new->all())->toBe([
        '2026_10_26_000001_create_referral_campaigns_table.php',
        '2026_10_26_000002_create_referral_codes_table.php',
        '2026_10_26_000003_create_user_referrals_table.php',
    ]);
});

test('AF11-74: collision exhaustion fails cleanly, a crashed reward delivery is replayed without double credit, and MySQL tables are InnoDB utf8mb4', function () {
    // تصادم الرموز: أبجدية من حرف واحد ⇒ المستخدم الثاني يستنفد المحاولات برسالة نظيفة ولا يُنشأ صف مكرر
    config(['referrals.code.alphabet' => 'B', 'referrals.code.length' => 1, 'referrals.code.max_collision_retries' => 3]);
    $one = e33_user();
    $two = e33_user();
    expect(e33_code($one))->toBe('B');

    try {
        e33_codes()->ensureFor($two);
        $this->fail('code_generation_failed expected');
    } catch (ReferralException $e) {
        expect($e->reason)->toBe('code_generation_failed');
    }

    expect(ReferralCode::query()->count())->toBe(1)->and(ReferralCode::query()->where('user_id', $two->id)->exists())->toBeFalse();
    config(['referrals.code.alphabet' => 'BCDFGHJKLMNPQRSTVWXZ2346789', 'referrals.code.length' => 6]);

    // انهيار بعد الائتمان وقبل تسجيل التسليم: إعادة التسليم لا تضاعف الرصيد ولا الدفتر
    e33_reward_campaign(default: true);
    $referrer = e33_user();
    [$referred, $ref] = e33_qualified($referrer);
    $ledger = e33_ledger();
    $walletReferrer = e33_wallet($referrer);
    $walletReferred = e33_wallet($referred);
    expect($ledger)->toHaveCount(2)->and($walletReferrer['pending'])->toBe(20)->and($walletReferred['pending'])->toBe(10);

    DB::table('user_referrals')->where('id', $ref->id)->update(['referrer_granted_at' => null, 'referred_granted_at' => null, 'status' => UserReferral::STATUS_QUALIFIED, 'reward_status' => UserReferral::REWARD_PENDING]);
    app(ReferralRewardService::class)->deliver($ref->id);
    app(ReferralRewardService::class)->deliver($ref->id);

    expect(e33_ledger())->toHaveCount(2)->and(e33_wallet($referrer))->toBe($walletReferrer)->and(e33_wallet($referred))->toBe($walletReferred)
        ->and(UserReferral::query()->find($ref->id)->status)->toBe(UserReferral::STATUS_REWARDED);

    // مفتاح الدفتر هو الحارس الأخير على مستوى القاعدة
    $dup = e33_ledger()->first();
    expect(fn () => DB::table('currency_transactions')->insert(collect($dup->getAttributes())->except('id')->all()))->toThrow(QueryException::class);

    if (DB::getDriverName() === 'mysql') {
        $rows = DB::select('select table_name as t, engine as e, table_collation as c from information_schema.tables where table_schema = database() and table_name in (?,?,?)', E33_TABLES);
        expect($rows)->toHaveCount(3);

        foreach ($rows as $row) {
            expect($row->e)->toBe('InnoDB')->and($row->c)->toStartWith('utf8mb4');
        }

        expect(DB::selectOne('select @@transaction_isolation as i')->i)->toBeString();
    }
});
