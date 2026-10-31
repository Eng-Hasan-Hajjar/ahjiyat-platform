<?php

namespace App\Services\AiAssistant\Tools;

use App\Services\AiAssistant\Dto\AiToolCall;
use App\Services\AiAssistant\Dto\AiToolResult;
use InvalidArgumentException;
use Throwable;

/**
 * E45 - سجل الأدوات الوحيد المسموح بها. قائمة صريحة مغلقة (لا اكتشاف تلقائي)، والتنفيذ بعد: وجود الاسم، أهلية الجمهور (الضيف لا يصل لأدوات حسابي)،
 * التحقق الصارم من الوسائط، سقف طول المخرجات، وعزل الأخطاء (خطأ أداة لا يُسقط الطلب ولا يسرّب رسالته).
 * الأسماء والوسائط الممنوعة (المادة 15 من المواصفة) تُرفض في البناء نفسه: لا يمكن تسجيل أداة تخالفها بالخطأ.
 */
final class ToolRegistry
{
    /** أسماء أدوات ممنوعة بالمواصفة؛ تسجيل أيٍّ منها يرمي استثناءً عند البناء. */
    public const FORBIDDEN_TOOLS = [
        'get_puzzle_answer', 'get_puzzle_solution', 'get_hint', 'raw_sql', 'run_query', 'get_user_by_email', 'get_private_chat',
        'get_moderation_case', 'get_payment_proof', 'get_api_secret', 'get_admin_config', 'read_file', 'read_env', 'execute_code', 'fetch_url',
    ];

    /** وسائط ممنوعة في أي أداة (هوية/أسرار/أوامر). */
    public const FORBIDDEN_PARAMETERS = ['user_id', 'user', 'email', 'username', 'public_id_of_user', 'player_id', 'password', 'token', 'sql', 'query_sql', 'url', 'path', 'file', 'command', 'code'];

    /** سقف حجم ناتج أداة واحدة (أحرف JSON) كي لا ينتفخ السياق. */
    public const MAX_OUTPUT_CHARS = 6000;

    /** @var array<string, AiTool> */
    private array $tools = [];

    /** @param list<AiTool>|null $tools */
    public function __construct(?array $tools = null)
    {
        foreach ($tools ?? self::catalog() as $tool) {
            $this->register($tool);
        }
    }

    /** القائمة المغلقة: 13 أداة عامة + 10 أدوات لحساب المستخدم نفسه. @return list<AiTool> */
    public static function catalog(): array
    {
        return [
            new Catalog\SearchPlatformTool,
            new Catalog\GetCurrentSeasonTool,
            new Catalog\GetSeasonInformationTool,
            new Catalog\GetCampaignInformationTool,
            new Catalog\GetCompetitionInformationTool,
            new Catalog\GetTeamPublicInformationTool,
            new Catalog\GetAhjiyatPassInformationTool,
            new Catalog\GetStoreItemInformationTool,
            new Catalog\GetCreatorPublicInformationTool,
            new Catalog\GetCreatorSeriesPublicInformationTool,
            new Catalog\GetWriterLeagueInformationTool,
            new Catalog\GetSupporterLeaderboardInformationTool,
            new Catalog\GetPlatformGuideArticleTool,

            new Catalog\GetMyProfileSummaryTool,
            new Catalog\GetMyProgressSummaryTool,
            new Catalog\GetMyCurrentCampaignProgressTool,
            new Catalog\GetMyCurrentSeasonStatusTool,
            new Catalog\GetMyPassStatusTool,
            new Catalog\GetMyCompetitionStatusTool,
            new Catalog\GetMyWriterStarBudgetTool,
            new Catalog\GetMySupporterRecognitionTool,
            new Catalog\GetMyCreatorStatusTool,
            new Catalog\GetMyPremiumSeriesAccessTool,
        ];
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->tools);
    }

    public function has(string $name): bool
    {
        return isset($this->tools[$name]);
    }

    public function get(string $name): ?AiTool
    {
        return $this->tools[$name] ?? null;
    }

    /** تعريفات الأدوات المتاحة لهذا السياق فقط (الضيف: العامة دون الشخصية). @return list<array{name: string, description: string, parameters: array<string, mixed>}> */
    public function definitionsFor(ToolContext $context): array
    {
        $out = [];

        foreach ($this->tools as $tool) {
            if ($tool->personal() && ! $context->hasMember()) {
                continue;
            }

            $out[] = $tool->definition();
        }

        return $out;
    }

    public function execute(AiToolCall $call, ToolContext $context): AiToolResult
    {
        $tool = $this->tools[$call->name] ?? null;

        if ($tool === null) {
            return AiToolResult::fail($call->name === '' ? 'unknown' : 'unknown_tool', 'unknown_tool', 'أداة غير معروفة.');
        }

        if ($tool->personal() && ! $context->hasMember()) {
            return AiToolResult::fail($tool->name(), 'forbidden', 'هذه المعلومة تخص حساب عضو موثَّق وسجّل دخوله.');
        }

        $arguments = $this->validate($tool, $call->arguments);

        if (is_string($arguments)) {
            return AiToolResult::fail($tool->name(), 'invalid_arguments', $arguments);
        }

        try {
            $result = $tool->run($context, $arguments);
        } catch (Throwable $e) {
            report($e);

            return AiToolResult::fail($tool->name(), 'unavailable', 'تعذر جلب هذه المعلومة الآن.');
        }

        if (mb_strlen($result->forModel()) > self::MAX_OUTPUT_CHARS) {
            return AiToolResult::fail($tool->name(), 'unavailable', 'النتيجة أكبر من المسموح؛ اطلب جزءًا أضيق.');
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>|string مصفوفة مُنقّاة، أو رسالة خطأ عربية قصيرة.
     */
    private function validate(AiTool $tool, array $arguments): array|string
    {
        $properties = $tool->properties();
        $clean = [];

        foreach ($arguments as $key => $value) {
            if (! is_string($key) || ! isset($properties[$key])) {
                return 'وسيط غير مسموح.';
            }
        }

        foreach ($properties as $key => $schema) {
            if (! array_key_exists($key, $arguments) || $arguments[$key] === null || $arguments[$key] === '') {
                if (in_array($key, $tool->required(), true)) {
                    return 'وسيط مطلوب مفقود.';
                }

                continue;
            }

            $value = $arguments[$key];

            switch ($schema['type']) {
                case 'string':
                    if (! is_string($value) || ! mb_check_encoding($value, 'UTF-8')) {
                        return 'قيمة وسيط غير صالحة.';
                    }

                    $value = trim(preg_replace('/[\x00-\x1F\x7F]/u', ' ', $value) ?? '');

                    if ($value === '' || mb_strlen($value) > (int) ($schema['maxLength'] ?? 80)) {
                        return 'قيمة وسيط غير صالحة.';
                    }

                    if (isset($schema['enum']) && ! in_array($value, $schema['enum'], true)) {
                        return 'قيمة وسيط غير صالحة.';
                    }

                    break;
                case 'integer':
                    if (is_string($value) && preg_match('/^-?\d{1,9}$/', $value) === 1) {
                        $value = (int) $value;
                    }

                    if (! is_int($value) || $value < (int) ($schema['minimum'] ?? 1) || $value > (int) ($schema['maximum'] ?? 20)) {
                        return 'قيمة وسيط غير صالحة.';
                    }

                    break;
                default:
                    return 'قيمة وسيط غير صالحة.';
            }

            $clean[$key] = $value;
        }

        return $clean;
    }

    private function register(AiTool $tool): void
    {
        $name = $tool->name();

        if (in_array($name, self::FORBIDDEN_TOOLS, true) || preg_match('/^[a-z][a-z0-9_]{2,63}$/', $name) !== 1 || isset($this->tools[$name])) {
            throw new InvalidArgumentException('أداة ذكاء غير مسموحة: '.$name);
        }

        foreach (array_keys($tool->properties()) as $parameter) {
            if (in_array($parameter, self::FORBIDDEN_PARAMETERS, true)) {
                throw new InvalidArgumentException('وسيط ممنوع في أداة '.$name.': '.$parameter);
            }
        }

        $this->tools[$name] = $tool;
    }
}
