<?php

use Illuminate\Pagination\Paginator;

/*
| E31/E32 hotfix - عزل حالة ساكنة بين الاختبارات.
| Livewire (صفحات Filament تحت Livewire::test) يستبدل عروض الترقيم الافتراضية ولا تعود بعد الاختبار؛
| فكان اختبار AA4-48 (رابط cursor في خلاصة المتابَعين) يفشل فقط عندما يسبقه اختبار Filament في نفس العملية (خطوة CI الحرجة على MySQL).
| الاختباران يعتمدان على ترتيب التعريف داخل الملف: الأول يحاكي التسرّب، والثاني يتحقق أن الاختبار التالي يبدأ بالعروض الافتراضية للإطار.
*/

test('ISO-1: a test that leaves Livewire pagination views behind (simulated leak)', function () {
    Paginator::defaultView('livewire::tailwind');
    Paginator::defaultSimpleView('livewire::simple-tailwind');

    expect(Paginator::$defaultSimpleView)->toBe('livewire::simple-tailwind');
});

test('ISO-2: the next test starts with the framework pagination views, so cursor/simple links render as plain <a href>', function () {
    expect(Paginator::$defaultView)->toBe('pagination::tailwind')
        ->and(Paginator::$defaultSimpleView)->toBe('pagination::simple-tailwind');
});

test('ISO-3: the base TestCase restores the views before the application boots', function () {
    Paginator::defaultSimpleView('livewire::simple-tailwind');

    \Tests\TestCase::restorePaginationViews();

    expect(Paginator::$defaultView)->toBe('pagination::tailwind')
        ->and(Paginator::$defaultSimpleView)->toBe('pagination::simple-tailwind');
});
