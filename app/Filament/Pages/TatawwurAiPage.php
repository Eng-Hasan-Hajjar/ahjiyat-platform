<?php

namespace App\Filament\Pages;

use App\Models\AiFeedback;
use App\Services\AiAssistant\Knowledge\KnowledgePublisher;
use App\Services\AiAssistant\TatawwurAnalytics;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * E45 - لوحة «تطوّر AI» الإدارية: حالة التشغيل (أعلام + جاهزية المزوّد بنعم/لا، **بلا أي مفتاح**)، الاستخدام والزمن والرموز، نسب الاعتماد على الدليل/الامتناع/الأعطال،
 * التقييمات وأسبابها، وفجوات المعرفة (ما امتنع عنه المساعد حسب المجال). **لا نص أسئلة ولا ردود ولا هوية مستخدمين**: المقاييس خالية من المحتوى بالبناء، ولا صلاحية لقراءة المحادثات.
 * الإعدادات (المزوّد/النموذج/الحدود/الأعلام) من بيئة الخادم حصرًا: لا حقل لتعديلها هنا. الإجراء الوحيد: إعادة بناء فهرس المقالات المنشورة (محلي، بلا شبكة).
 */
class TatawwurAiPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-sparkles';

    protected static ?string $navigationGroup = 'المشاركة';

    protected static ?string $navigationLabel = 'لوحة تطوّر AI';

    protected static ?string $title = 'تطوّر AI: الاستخدام والمعرفة';

    protected static ?string $slug = 'tatawwur-ai';

    protected static ?int $navigationSort = 75;

    protected static string $view = 'filament.pages.tatawwur-ai';

    public int $days = 7;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('ai-assistant.view') ?? false;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    public function setDays(int $days): void
    {
        abort_unless(static::canAccess(), 403);
        $this->days = in_array($days, [1, 7, 30, 90], true) ? $days : 7;
    }

    /** @return array{status: array<string, mixed>, overview: array<string, mixed>, reasons: array<string, string>} */
    public function data(): array
    {
        $analytics = app(TatawwurAnalytics::class);

        return [
            'status' => $analytics->status(),
            'overview' => $analytics->overview($this->days),
            'reasons' => [
                'inaccurate' => 'غير دقيق', 'incomplete' => 'ناقص', 'unsafe' => 'غير آمن', 'off_topic' => 'خارج الموضوع', 'other' => 'آخر',
            ],
            'canPublish' => auth()->user()?->can('ai-assistant.knowledge.publish') ?? false,
            'knownReasons' => AiFeedback::REASONS,
        ];
    }

    public function rebuild(): void
    {
        abort_unless(auth()->user()?->can('ai-assistant.knowledge.publish'), 403);

        $result = app(KnowledgePublisher::class)->rebuild();

        Notification::make()->success()->title('أُعيد بناء الفهرس')
            ->body("{$result['articles']} مقالًا و{$result['chunks']} مقطعًا"
                .($result['reverted'] !== [] ? ' · عاد مسودة: '.count($result['reverted']) : '')
                .($result['blocked'] !== [] ? ' · سُحب لفشل الفحص: '.count($result['blocked']) : ''))
            ->send();
    }
}
