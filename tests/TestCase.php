<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Pagination\Paginator;
use Tests\Support\TestDatabaseGuard;

abstract class TestCase extends BaseTestCase
{
    /**
     * عروض الترقيم الافتراضية كما كانت قبل أي اختبار في هذه العملية (تُلتقط مرة واحدة).
     *
     * @var array{0: string, 1: string}|null
     */
    private static ?array $paginationViews = null;

    /**
     * Livewire يستبدل Paginator::$defaultView/$defaultSimpleView عند إقلاع أي مكوّن بـWithPagination (صفحات Filament)
     * ويُفترض أن يعيدهما في destroy()، لكن لوحظ أنهما يبقيان بعد Livewire::test (livewire::simple-tailwind: أزرار wire:click
     * بدل روابط href) فيرثهما أي اختبار HTTP لاحق في نفس العملية، فيختفي رابط المؤشر عن صفحات cursorPaginate.
     * نعيدهما قبل إقلاع التطبيق (كي يبقى أي تعيين تفعله مزوّدات التطبيق نفسها عند الإقلاع هو المعتمد).
     * سلوك الإنتاج لا يتأثر: كل طلب في عملية مستقلة.
     */
    public static function restorePaginationViews(): void
    {
        self::$paginationViews ??= [Paginator::$defaultView, Paginator::$defaultSimpleView];

        Paginator::defaultView(self::$paginationViews[0]);
        Paginator::defaultSimpleView(self::$paginationViews[1]);
    }

    /**
     * refreshApplication() تسبق setUpTraits() (أي تسبق migrate:fresh الخاصة
     * بـRefreshDatabase)، فيتوقف التشغيل هنا قبل أن يُمسَح أي شيء.
     */
    protected function refreshApplication()
    {
        self::restorePaginationViews();

        parent::refreshApplication();

        $connection = config('database.default');
        $config = config("database.connections.{$connection}", []);

        TestDatabaseGuard::assertSafe(
            (string) ($config['driver'] ?? ''),
            isset($config['database']) ? (string) $config['database'] : null,
            $this->app->configurationIsCached(),
        );
    }
}
