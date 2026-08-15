<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VolunteerProfile extends Model
{
    use HasFactory;

protected $fillable = [
        'user_id', 'skills', 'other_skill_note', 'availability', 'hours_per_week',
        'total_hours_contributed', 'points', 'experience_level',
        'certifications', 'has_vehicle', 'travel_distance_km',
        'completed_projects', 'rating', 'rating_count',
    ];

    protected $casts = [
        'skills'       => 'array',
        'availability' => 'array',
        'has_vehicle'  => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public static function allSkills(): array
    {
        return [
            'carpentry'    => 'نجارة',
            'electrical'   => 'كهرباء',
            'plumbing'     => 'سباكة',
            'painting'     => 'دهان',
            'masonry'      => 'بناء وتشييد',
            'ironwork'     => 'حدادة',
            'tiling'       => 'تبليط',
            'plastering'   => 'لياسة',
            'cleaning'     => 'تنظيف وترتيب',
            'logistics'    => 'لوجستيات ونقل',
            'coordination' => 'تنسيق وإدارة',
            'other'        => 'أخرى',
        ];
    }

    /**
     * سلّم شارات المستوى، من الأعلى (بلاتيني) للأدنى (مبتدئ).
     * ملاحظة مهمة: الأيقونة هون دائرة ملوّنة (⬤) وليست ميدالية (🥇)
     * عن قصد — منشان ما تتلخبط أبداً مع ميدالية "المركز" بلوحة المتصدرين.
     */
    public static function badgeTiers(): array
    {
        return [
            ['min' => 500, 'label' => 'بلاتيني', 'color' => '#7c3aed', 'bg' => '#f3e8ff', 'icon' => '🟣'],
            ['min' => 200, 'label' => 'ذهبي',    'color' => '#b45309', 'bg' => '#fef3c7', 'icon' => '🟡'],
            ['min' => 100, 'label' => 'فضي',     'color' => '#475569', 'bg' => '#f1f5f9', 'icon' => '⚪'],
            ['min' => 50,  'label' => 'برونزي',  'color' => '#92400e', 'bg' => '#fed7aa', 'icon' => '🟤'],
            ['min' => 0,   'label' => 'مبتدئ',   'color' => '#16a34a', 'bg' => '#dcfce7', 'icon' => '🟢'],
        ];
    }

    /**
     * شارة المستوى الحالية للمتطوع + نسبة التقدم نحو الشارة التالية.
     * المفاتيح القديمة (label, color, icon) محفوظة كما هي للتوافق مع كل الصفحات
     * التي تستخدمها حالياً (index, show, profile, dashboard)، مع إضافة مفاتيح جديدة.
     */
    public function getBadgeAttribute(): array
    {
        $points = $this->points ?? 0;
        $tiers  = self::badgeTiers();

        foreach ($tiers as $i => $tier) {
            if ($points >= $tier['min']) {
                $next = $tiers[$i - 1] ?? null; // الشارة الأعلى مباشرة

                return array_merge($tier, [
                    'points_to_next' => $next ? max(0, $next['min'] - $points) : 0,
                    'next_label'     => $next['label'] ?? null,
                    'progress'       => $next
                        ? min(100, round((($points - $tier['min']) / max(1, $next['min'] - $tier['min'])) * 100))
                        : 100,
                ]);
            }
        }

        return array_merge(end($tiers), ['points_to_next' => 0, 'next_label' => null, 'progress' => 100]);
    }

/**
     * أسماء المهارات بالعربي — إذا اختار المتطوع "أخرى" وكتب توضيحاً،
     * بيتعوّض النص العام "أخرى" بالنص يلي كتبه هو بنفسه.
     */
    public function getSkillsArabicAttribute(): array
    {
        $all  = self::allSkills();
        $mine = array_filter($all, fn($key) => in_array($key, $this->skills ?? []), ARRAY_FILTER_USE_KEY);

        if (isset($mine['other']) && !empty($this->other_skill_note)) {
            $mine['other'] = $this->other_skill_note;
        }

        return $mine;
    }
}