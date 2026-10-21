<?php

namespace App\Services\Editorial;

/**
 * E35 - القائمة المغلقة لأنواع محتوى الأرفف التحريرية. القيمة تُخزَّن كما هي بـeditorial_shelf_items.content_type؛
 * **لا** اسم صنف PHP ولا مسار ولا URL ولا HTML يُخزَّن أبدًا: الحل وقت القراءة بدفعة واحدة لكل نوع (EditorialShelfResolver).
 */
enum EditorialContentType: string
{
    case OfficialPuzzle = 'official_puzzle';
    case CommunityPuzzle = 'community_puzzle';
    case CreatorSeries = 'creator_series';
    case Campaign = 'campaign';
    case Season = 'season';
    case CompetitiveEvent = 'competitive_event';
    case PublicCollection = 'public_collection';

    public function label(): string
    {
        return match ($this) {
            self::OfficialPuzzle => 'أحجية رسمية',
            self::CommunityPuzzle => 'أحجية من المجتمع',
            self::CreatorSeries => 'سلسلة من المجتمع',
            self::Campaign => 'حملة',
            self::Season => 'موسم',
            self::CompetitiveEvent => 'منافسة',
            self::PublicCollection => 'مجموعة من المجتمع',
        };
    }

    /** @return array<string, string> value => label */
    public static function options(): array
    {
        $out = [];

        foreach (self::cases() as $case) {
            $out[$case->value] = $case->label();
        }

        return $out;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
