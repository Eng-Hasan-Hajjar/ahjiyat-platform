<?php

namespace Database\Seeders\Demo;

use App\Models\Currency;
use App\Models\StoreItem;
use App\Models\StoreItemPrice;
use App\Models\StorePurchase;
use App\Models\XpTransaction;
use App\Services\Economy\CurrencyRegistry;
use App\Services\Economy\CurrencyWalletService;
use App\Services\PlayerIdentity\CosmeticLoadoutService;
use App\Services\Progression\XpService;
use App\Services\PuzzleAttemptService;
use App\Services\Store\StorePurchaseService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

/**
 * التقدّم والاقتصاد والمتجر والالتزام (B + C2) **بالخدمات الرسمية فقط**: لا تعديل مباشر لأي رصيد أو XP أو سلسلة.
 *  - XP قاعدية (XpService) ورصيد قاعدي (CurrencyWalletService): قيم ثابتة، المفتاح الدلالي يمنع التكرار.
 *  - متجر: عناصر ديمو بأسعار، مشتريات حقيقية (StorePurchaseService بمفتاح طلب ثابت) وتجهيز (CosmeticLoadoutService).
 *  - تاريخ الأحجيات: محاولات عبر PuzzleAttemptService بزمن محاكى ← الجواهر والـXP والمهام اليومية/الأسبوعية والسلسلة والإنجازات تنتج **طبيعيًا** (مصدرها محاولات حقيقية).
 * Idempotent: من حلّ أحجية لا يعيدها (الخدمة ترفض)، والخطوات الأخرى بمفاتيح.
 */
class DemoProgressionSeeder extends Seeder
{
    use DemoSupport;

    /** XP قاعدية (تتراكم فوقها XP الأحجيات الطبيعية): تنوّع المستويات. */
    public const BASELINE_XP = [
        'yousef' => 700, 'reem' => 1900, 'noureddine' => 520, 'shatha' => 350, 'sara' => 260, 'malak' => 200, 'anas' => 150, 'omar' => 120,
        'raghad' => 90, 'eman' => 80, 'layan' => 60, 'wisam' => 40, 'basel' => 30, 'adnan' => 25, 'jana' => 20, 'dana' => 10,
    ];

    /** رصيد قاعدي متاح: [earned, premium]. */
    public const BASELINE_WALLET = [
        'yousef' => [1250, 600], 'sara' => [700, 150], 'reem' => [2100, 300], 'omar' => [300, 0], 'noureddine' => [450, 0], 'layan' => [120, 0], 'malak' => [260, 0],
    ];

    /** عناصر المتجر: sku => [اسم, نوع, فتحة, سعر earned, سعر premium]. (العملة المميَّزة غير قابلة للصرف بالنظام الحالي: كل الأسعار بالمكتسبة.) */
    public const ITEMS = [
        'demo-frame-gold' => ['إطار الذهب', 'cosmetic', 'profile_frame', 300, null],
        'demo-badge-knight' => ['شارة الفارس', 'cosmetic', 'badge', 150, null],
        'demo-title-hunter' => ['لقب صائد الألغاز', 'cosmetic', 'title', 250, null],
        'demo-background-night' => ['خلفية الليل', 'cosmetic', 'profile_background', 400, null],
        'demo-hint-pack' => ['حزمة تلميحات (3)', 'consumable', null, 40, null],
    ];

    /** [user, sku, equip?] */
    public const PURCHASES = [
        ['yousef', 'demo-frame-gold', true], ['yousef', 'demo-badge-knight', false], ['yousef', 'demo-hint-pack', false],
        ['sara', 'demo-title-hunter', true], ['sara', 'demo-badge-knight', false],
        ['reem', 'demo-frame-gold', true], ['reem', 'demo-badge-knight', true], ['reem', 'demo-background-night', false],
        ['omar', 'demo-badge-knight', true],
    ];

    /** خطة اللعب: [قبل كم يومًا، مفتاح الأحجية، النمط]. النمط: ok (صحيحة)، miss (خطأ ثم صحيحة)، fail (ثلاث محاولات خاطئة). اليوم = 0. */
    public const PLANS = [
        'yousef' => [[20, 'S01'], [15, 'S02', 'miss'], [12, 'S03'], [9, 'S10', 'fail'], [6, 'S04'], [5, 'S05'], [4, 'S06', 'miss'], [3, 'S07'], [2, 'S08'], [1, 'S09'], [0, 'S12'], [0, 'S13']],
        'sara' => [[14, 'S01'], [11, 'S02'], [9, 'S03', 'miss'], [7, 'S04'], [5, 'S05'], [3, 'S06'], [2, 'S07'], [1, 'S08'], [0, 'S09']],
        'reem' => [[25, 'S01'], [22, 'S02'], [20, 'S03'], [18, 'S04'], [16, 'S05'], [14, 'S06'], [12, 'S07'], [10, 'S08'], [8, 'S09'], [6, 'S10'], [5, 'S11'], [4, 'S12'], [3, 'S13'], [2, 'S14'], [1, 'S15'], [0, 'S16']],
        'omar' => [[10, 'S01'], [8, 'S02'], [6, 'S03', 'miss'], [3, 'S04'], [1, 'S05']],
        'noureddine' => [[18, 'S01'], [15, 'S02'], [12, 'S03'], [10, 'S04'], [7, 'S05'], [5, 'S06'], [2, 'S07']],
        'layan' => [[7, 'S01'], [5, 'S02'], [3, 'S03'], [1, 'S04']],
        'jana' => [[5, 'S01', 'miss'], [2, 'S02']],
        'yaser' => [[12, 'S01'], [9, 'S02'], [4, 'S03']],
        'malak' => [[16, 'S01'], [13, 'S02'], [9, 'S03'], [6, 'S04'], [2, 'S05']],
        'wisam' => [[3, 'S01'], [1, 'S02']], 'shatha' => [[30, 'S01'], [20, 'S02'], [10, 'S03'], [4, 'S04']], 'anas' => [[14, 'S01'], [7, 'S02']],
        'lama' => [[10, 'S01'], [3, 'S02', 'fail']], 'khaled' => [[6, 'S01']], 'raghad' => [[8, 'S01'], [5, 'S02']], 'basel' => [[4, 'S01']],
        'eman' => [[9, 'S01'], [3, 'S02']], 'adnan' => [[2, 'S01']], 'firas' => [[20, 'S01'], [11, 'S02']], 'dana' => [[6, 'S01']],
    ];

    public function run(): void
    {
        DemoPuzzleCatalog::ensure();

        $this->quiet(function () {
            $this->baseline();
            $this->store();
            $this->history();
        });

        $this->say('تقدّم: XP/رصيد قاعدي + متجر (مملوك/مجهَّز/غير مملوك) + تاريخ أحجيات (صحيحة/خاطئة/فاشلة) بسلسلة ومهام طبيعية.');
    }

    protected function baseline(): void
    {
        $earned = app(CurrencyRegistry::class)->defaultEarnedCurrency();
        $premium = Currency::query()->where('internal_key', 'platform-premium')->firstOrFail();
        $wallet = app(CurrencyWalletService::class);
        $xp = app(XpService::class);

        foreach (self::BASELINE_XP as $key => $amount) {
            $this->at(now()->subDays(50), fn () => $xp->grantXp($this->user($key), $amount, XpTransaction::TYPE_PUZZLE_SOLVE, 'demo_qa_baseline', null, "demo-qa:xp-baseline:{$key}"));
        }

        foreach (self::BASELINE_WALLET as $key => [$e, $p]) {
            $user = $this->user($key);
            $done = \App\Models\CurrencyTransaction::query()->where('user_id', $user->id)->where('reason', 'demo_qa_baseline')->exists();

            if (! $done) {
                $this->at(now()->subDays(49), function () use ($wallet, $user, $earned, $premium, $e, $p) {
                    $e > 0 && $wallet->creditAvailable($user, $earned, $e, 'demo_qa_baseline');
                    $p > 0 && $wallet->creditAvailable($user, $premium, $p, 'demo_qa_baseline');
                });
            }
        }
    }

    protected function store(): void
    {
        $earned = app(CurrencyRegistry::class)->defaultEarnedCurrency();
        $premium = Currency::query()->where('internal_key', 'platform-premium')->firstOrFail();
        $items = [];

        foreach (self::ITEMS as $sku => [$name, $type, $slot, $priceEarned, $pricePremium]) {
            $item = StoreItem::query()->where('sku', $sku)->first() ?? tap(new StoreItem([
                'sku' => $sku, 'slug' => $sku, 'name' => $name, 'short_description' => $name, 'description' => 'عنصر تجريبي لبيانات الاختبار.', 'item_type' => $type,
                'fulfillment_type' => StoreItem::FULFILLMENT_INVENTORY, 'is_active' => true, 'is_featured' => $sku === 'demo-frame-gold', 'cosmetic_slot' => $slot,
                'cosmetic_text' => $slot === 'title' ? 'صائد الألغاز' : null, 'cosmetic_color' => $slot === 'title' ? '#7C3AED' : null,
                'grant_quantity' => $type === 'consumable' ? 3 : 1, 'sort_order' => array_search($sku, array_keys(self::ITEMS), true) + 1,
                'image_path' => $slot !== null && $slot !== 'title' ? $this->placeholder($sku, $name) : null,
            ]))->save();

            foreach ([[$earned, $priceEarned], [$premium, $pricePremium]] as [$currency, $amount]) {
                $amount !== null && StoreItemPrice::query()->firstOrCreate(['store_item_id' => $item->id, 'currency_id' => $currency->id], ['amount' => $amount, 'is_active' => true, 'sort_order' => 1]);
            }

            $items[$sku] = $item;
        }

        $purchases = app(StorePurchaseService::class);
        $loadout = app(CosmeticLoadoutService::class);

        foreach (self::PURCHASES as [$key, $sku, $equip]) {
            $user = $this->user($key);
            $item = $items[$sku];
            $request = "demo-qa:{$key}:{$sku}";

            if (! StorePurchase::query()->where('user_id', $user->id)->where('request_key', $request)->exists()) {
                $price = StoreItemPrice::query()->where('store_item_id', $item->id)->orderBy('id')->firstOrFail();
                $this->at(now()->subDays(30), fn () => $purchases->purchase($user, $item, $price, $request));
            }

            $equip && $loadout->canEquip($user, $item) && $loadout->equippedForSlot($user, $item->cosmetic_slot)?->getKey() !== $item->getKey() && $loadout->equip($user, $item);
        }
    }

    /** صورة بديلة SVG للعناصر التجميلية (لا تعتمد GD). Idempotent. */
    protected function placeholder(string $sku, string $label): string
    {
        $path = "demo/store/{$sku}.svg";

        if (! Storage::disk('public')->exists($path)) {
            $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="400" height="400" viewBox="0 0 400 400"><rect width="400" height="400" rx="48" fill="#0f1226"/>'
                .'<rect x="12" y="12" width="376" height="376" rx="40" fill="none" stroke="#8b5cf6" stroke-width="6"/>'
                .'<text x="200" y="210" font-size="34" text-anchor="middle" fill="#e2e8f0" font-family="sans-serif">'.htmlspecialchars($label, ENT_XML1).'</text></svg>';
            Storage::disk('public')->put($path, $svg);
        }

        return $path;
    }

    /** زمن محاولة "اليوم": قبل الآن بدقائق لكن **لا قبل منتصف الليل** (فتبقى مهام اليوم وسلسلته صحيحة مهما كان وقت التشغيل). */
    protected function today(int $slot)
    {
        $candidate = now()->subMinutes(40 + 25 * (3 - min($slot, 3)));
        $midnight = now()->startOfDay()->addMinutes(1 + $slot);

        return $candidate->lessThan($midnight) ? $midnight : $candidate;
    }

    protected function history(): void
    {
        $attempts = app(PuzzleAttemptService::class);

        foreach (self::PLANS as $key => $plan) {
            $user = $this->user($key);
            $byDay = [];

            foreach ($plan as $i => $entry) {
                [$days, $puzzleKey] = $entry;
                $mode = $entry[2] ?? 'ok';
                $puzzle = DemoPuzzleCatalog::puzzle($puzzleKey);
                $slot = $byDay[$days] = ($byDay[$days] ?? -1) + 1;

                // اليوم: أزمنة قبل الآن بدقائق (لا مستقبل)، وغيره: ساعات نهار ثابتة.
                $base = $days === 0 ? $this->today($slot) : now()->subDays($days)->setTime(11 + $slot * 3, 5 + $i % 40);

                if (\App\Models\PuzzleAttempt::query()->where('user_id', $user->id)->where('puzzle_id', $puzzle->id)->exists()) {
                    continue;                                                         // Idempotent: الخدمة نفسها تمنع إعادة حل أحجية محلولة
                }

                $this->at($base, function () use ($attempts, $user, $puzzle, $puzzleKey, $mode) {
                    if ($mode === 'miss' || $mode === 'fail') {
                        $attempts->attempt($user, $puzzle, 'إجابة خاطئة 1');
                    }

                    if ($mode === 'fail') {
                        $this->at(now()->addMinutes(2), fn () => $attempts->attempt($user, $puzzle, 'إجابة خاطئة 2'));
                        $this->at(now()->addMinutes(4), fn () => $attempts->attempt($user, $puzzle, 'إجابة خاطئة 3'));

                        return;
                    }

                    $this->at(now()->addMinutes($mode === 'miss' ? 3 : 0), fn () => $attempts->attempt($user, $puzzle, DemoPuzzleCatalog::answer($puzzleKey)));
                });
            }
        }
    }
}
