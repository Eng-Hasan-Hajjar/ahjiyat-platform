<?php

namespace Database\Seeders\Demo;

use App\Models\ChatMessage;
use App\Models\ChatThread;
use App\Models\Team;
use App\Models\User;
use App\Services\Chat\ChatException;
use App\Services\Chat\ChatMessageService;
use App\Services\Chat\ChatMuteService;
use App\Services\Chat\ChatReportService;
use App\Services\Chat\ChatThreadService;
use App\Services\Teams\TeamNaming;
use Illuminate\Database\Seeder;

/**
 * دردشة الديمو (E21-M): **بخدمات الدردشة الرسمية** (الإرسال/التعديل/الحذف/القراءة/البلاغ/الكتم) وبزمن محاكى، وبثّ صامت (لا محاولات Reverb أثناء البذر). حتمية، Idempotent،
 * ولا إشعار ولا اقتصاد ولا XP (الدردشة لا تملك أيًّا منها). يوسف يستطيع فورًا تجربة: مباشرة (سارة وليان)، فريقه، والعامة.
 *
 *  مباشرة يوسف↔سارة: 8 رسائل، **واحدة غير مقروءة** | يوسف↔ليان: 4 رسائل مقروءة | فريق «فرسان الشام»: 10 رسائل من 5 أعضاء (إحداها معدّلة)، 4 غير مقروءة
 *  العامة: 18 رسالة من 18 مستخدمًا مختلفًا (منها لـلمى وفراس المحظورَين من يوسف: تُرشَّح من عرضه، ورسالة محذوفة)، **بلاغ معلّق** على رسالة إعلانية من خالد، ووسام **مكتوم** 7 أيام (ليس يوسف).
 */
class DemoChatSeeder extends Seeder
{
    use DemoSupport;

    /** [المرسل، قبل كم دقيقة، النص] مرتّبة من الأقدم إلى الأحدث. */
    public const SARA = [
        ['sara', 4300, 'صباح الخير يوسف! شفت الجولة الجديدة؟'], ['yousef', 4290, 'صباح النور سارة، جاهز للتحدي 💪'], ['sara', 3000, 'فريقنا «صقور المعرفة» رح يفوز هالمرة 😄'],
        ['yousef', 2990, 'نشوف! «فرسان الشام» مو سهلين.'], ['sara', 1500, 'تعال نلعب تحدي أصدقاء الليلة؟'], ['yousef', 1480, 'تمام بعد العشاء إن شاء الله'],
        ['sara', 120, 'أرسلت لك التحدي، اقبله لما تفضى'], ['sara', 15, 'بانتظارك! ⏳'],
    ];

    public const LAYAN = [
        ['layan', 2900, 'مبروك على المركز الأول بالجولة 4! 🏆'], ['yousef', 2890, 'شكرًا ليان، بفضل الفريق'], ['layan', 2880, 'وقت التدريب غدًا؟'], ['yousef', 2870, 'اتفقنا الساعة 8'],
    ];

    public const TEAM = [
        ['yousef', 2600, 'أهلًا بالجميع في دردشة فرسان الشام 👋'], ['omar', 2590, 'جاهزين للجولة الجاية'], ['layan', 2570, 'أنا حليت أحجية اليوم بسرعة'], ['jana', 2540, 'تحدّي فريق بانتظارنا مع صقور المعرفة؟'],
        ['yaser', 2500, 'أنا معكم، أي وقت'], ['yousef', 2480, 'ممتاز، نتفق الليلة'], ['omar', 600, 'تذكير: تحدّي الفريق ينتهي بعد 44 ساعة'], ['layan', 590, 'تم، بلعب حالًا'],
        ['jana', 580, 'وأنا بعدها'], ['yaser', 570, 'بالتوفيق للجميع 🙌'],
    ];

    public const GLOBAL = [
        ['reem', 2800, 'مرحبًا بالجميع في الدردشة العامة 🌍'], ['omar', 2780, 'أهلًا ريم، الجولة 4 كانت قوية'], ['noureddine', 2700, 'من جرّب أحجية الذاكرة بالحملة؟'], ['lama', 2650, 'رسالة من لمى (محظورة من يوسف: لا تظهر له)'],
        ['layan', 2600, 'أنا! صعبة لكن ممتعة'], ['malak', 2500, 'نصيحة: ركّزوا على الأنماط'], ['wisam', 2400, 'تكرار تكرار تكرار (رسالة وسام قبل كتمه)'], ['khaled', 2000, 'اشتروا من متجرنا الآن! خصم 90% 🔥 (رسالة إعلانية تجريبية للإشراف)'],
        ['shatha', 1800, 'بطولة الشتاء بدأت، حظًا موفقًا للجميع'], ['firas', 1700, 'رسالة من فراس (حظر يوسف: لا تظهر له)'], ['jana', 1500, 'هل من أحد يلعب الجولة الحيّة الآن؟'], ['anas', 1400, 'أنا سجّلت للتو'],
        ['eman', 1300, 'سؤال: كم مدة تعديل الرسالة؟'], ['yousef', 1000, 'تحياتي من فرسان الشام! 🛡️'], ['raghad', 900, 'مرحبًا يوسف 👋'], ['basel', 800, 'رسالة بالخطأ (سيحذفها صاحبها)'],
        ['kenan', 120, 'أنا لاعب جديد، كيف أبدأ؟'], ['adnan', 60, 'أهلًا كنان! ابدأ من حملة أصيل'],
    ];

    public function run(): void
    {
        $this->silentBroadcast(function () {
            $threads = app(ChatThreadService::class);
            $yousef = $this->user('yousef');

            $this->direct($threads, $yousef, $this->user('sara'), self::SARA, readAfter: 7);       // يقرأ حتى رسالة سارة السابعة؛ الثامنة وحدها غير مقروءة
            $this->direct($threads, $yousef, $this->user('layan'), self::LAYAN, readAfter: 4);      // الكل مقروء
            $this->team($threads, $yousef);
            $this->global($threads, $yousef);
            $this->mute();
        });

        $this->say('دردشة: مباشرتان ليوسف (واحدة بغير مقروء) + فريق (10) + عامة (18) + بلاغ معلّق + كتم مؤقت لوسام.');
    }

    protected function direct(ChatThreadService $threads, User $me, User $other, array $plan, int $readAfter): void
    {
        $existing = $threads->findDirect($me, $other);

        if ($existing !== null && ChatMessage::query()->where('chat_thread_id', $existing->id)->exists()) {
            return;                                                                                 // Idempotent
        }

        $thread = $threads->directOrCreate($me, $other);
        $ids = $this->send($thread, $plan);
        $this->at(now()->subMinutes($plan[$readAfter - 1][1] - 5), fn () => app(ChatMessageService::class)->markRead($me, $thread, $ids[$readAfter - 1]));
    }

    protected function team(ChatThreadService $threads, User $me): void
    {
        $team = Team::query()->where('name_key', TeamNaming::key(TeamNaming::normalize('فرسان الشام')))->firstOrFail();
        $thread = $threads->forTeam($team);

        if (ChatMessage::query()->where('chat_thread_id', $thread->id)->exists()) {
            return;
        }

        $ids = $this->send($thread, self::TEAM);

        // ليان تعدّل رسالتها خلال النافذة (معدّلة). يوسف يقرأ حتى رسالته السادسة؛ الأربع الأخيرة غير مقروءة.
        $edited = ChatMessage::query()->findOrFail($ids[2]);
        $this->at($edited->created_at->copy()->addMinutes(2), fn () => app(ChatMessageService::class)->edit($this->user('layan'), $edited, 'أنا حليت أحجية اليوم بسرعة 🎯'));
        $this->at(now()->subMinutes(2400), fn () => app(ChatMessageService::class)->markRead($me, $thread, $ids[5]));
    }

    protected function global(ChatThreadService $threads, User $me): void
    {
        $thread = $threads->global();

        if (ChatMessage::query()->where('chat_thread_id', $thread->id)->where('body', self::GLOBAL[0][2])->exists()) {
            $this->report($thread);

            return;
        }

        $ids = $this->send($thread, self::GLOBAL);

        // باسل يحذف رسالته (Tombstone). يوسف يقرأ حتى رسالة «أنس» (الرابعة عشرة)، فيبقى عنده غير مقروء ظاهر من آخرين.
        $basel = ChatMessage::query()->findOrFail($ids[15]);
        $this->at($basel->created_at->copy()->addMinutes(3), fn () => app(ChatMessageService::class)->deleteOwn($this->user('basel'), $basel));
        $this->at(now()->subMinutes(1200), fn () => app(ChatMessageService::class)->markRead($me, $thread, $ids[11]));
        $this->report($thread);
    }

    /** بلاغ معلّق من سارة على الرسالة الإعلانية (Idempotent: الخدمة ترفض التكرار). */
    protected function report(ChatThread $thread): void
    {
        $spam = ChatMessage::query()->where('chat_thread_id', $thread->id)->where('body', 'like', 'اشتروا من متجرنا%')->first();

        if ($spam === null) {
            return;
        }

        try {
            $this->at(now()->subMinutes(1900), fn () => app(ChatReportService::class)->report($this->user('sara'), $spam, 'spam', 'إعلان مزعج متكرر (بيانات تجريبية)'));
        } catch (ChatException $e) {
            if ($e->reason !== 'already_reported') {
                throw $e;
            }
        }
    }

    /** وسام مكتوم 7 أيام بسبب موثَّق (ليس يوسف). يُعاد الكتم فقط إن انتهى السابق. */
    protected function mute(): void
    {
        $wisam = $this->user('wisam');
        $mutes = app(ChatMuteService::class);

        if ($mutes->activeFor($wisam) === null) {
            $mutes->mute(User::query()->where('email', 'admin@ahjiyat.app')->firstOrFail(), $wisam, '7d', 'تكرار رسائل الإزعاج بالدردشة العامة (بيانات تجريبية)');
        }
    }

    /** يرسل خطة رسائل بالترتيب الزمني عبر الخدمة الرسمية. @return list<int> معرّفات الرسائل بالترتيب. */
    protected function send(ChatThread $thread, array $plan): array
    {
        $service = app(ChatMessageService::class);
        $ids = [];

        foreach ($plan as [$key, $minutesAgo, $body]) {
            $ids[] = $this->at(now()->subMinutes($minutesAgo), fn () => $service->send($this->user($key), $thread, $body)->getKey());
        }

        return $ids;
    }
}
