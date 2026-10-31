<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * E45 - مقياس تشغيلي لطلب مساعد واحد: **بلا نص سؤال ولا ردّ ولا هوية مستخدم** (الجمهور member|guest فقط). يغذّي الميزانية اليومية العامة للرموز وصحة المزوّد وفجوات المعرفة.
 * لا يُعرَض ترتيب/إنفاق للمستخدمين في أي مكان.
 */
class AiRequestMetric extends Model
{
    public const OUTCOMES = ['answered', 'abstained', 'refused', 'fallback', 'out_of_scope', 'error', 'limited'];

    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'grounded' => 'boolean',
            'streamed' => 'boolean',
            'tool_calls' => 'integer',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'latency_ms' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $metric): void {
            $metric->public_id ??= (string) Str::ulid();
        });
    }
}
