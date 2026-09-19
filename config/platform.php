<?php

/**
 * القيم الافتراضية لكل إعدادات المنصة، منظَّمة حسب Group - هذا هو مصدر
 * الحقيقة الوحيد للـDefaults (لا Seeder صفوف لكل إعداد). قيمة قاعدة
 * البيانات (platform_settings) تتغلّب على القيمة هنا فقط إن وُجدت صراحة؛
 * غيابها = استخدم ما هنا. إضافة إعداد جديد مستقبلاً = سطر جديد هنا +
 * حقل بصفحة الإدارة - لا Migration. راجع docs/platform-settings.md.
 */
return [

    'general' => [
        'site_name' => ['type' => 'string', 'default' => 'أحجيات'],
        'short_name' => ['type' => 'string', 'default' => 'أحجيات'],
        'short_description' => ['type' => 'text', 'default' => 'منصة ألغاز وتحديات ذهنية بنظام الجواهر.'],
        'tagline' => ['type' => 'string', 'default' => 'حلّ الألغاز، اجمع الجواهر، وتصدّر لوحة الصدارة'],
        'public_email' => ['type' => 'string', 'default' => null],
        'support_email' => ['type' => 'string', 'default' => null],
        'contact_phone' => ['type' => 'string', 'default' => null],
        'display_timezone' => ['type' => 'string', 'default' => 'Asia/Damascus'],
    ],

    'branding' => [
        'logo_main' => ['type' => 'image', 'default' => null],
        'logo_light' => ['type' => 'image', 'default' => null],
        'logo_dark' => ['type' => 'image', 'default' => null],
        'logo_square' => ['type' => 'image', 'default' => null],
        'favicon' => ['type' => 'image', 'default' => null],
        'social_share_image' => ['type' => 'image', 'default' => null],
    ],

    'appearance' => [
        'default_theme' => ['type' => 'string', 'default' => 'dark'],
        'allow_theme_switch' => ['type' => 'boolean', 'default' => true],
        'color_preset' => ['type' => 'string', 'default' => 'amethyst'],
        'color_primary' => ['type' => 'color', 'default' => '#8b5cf6'],
        'color_secondary' => ['type' => 'color', 'default' => '#22d3ee'],
        'color_accent' => ['type' => 'color', 'default' => '#fcd34d'],
        'color_success' => ['type' => 'color', 'default' => '#34d399'],
        'color_warning' => ['type' => 'color', 'default' => '#fbbf24'],
        'color_danger' => ['type' => 'color', 'default' => '#fb7185'],
        'font_family' => ['type' => 'string', 'default' => 'cairo'],
        'base_font_size' => ['type' => 'string', 'default' => 'normal'],
        'ui_radius' => ['type' => 'string', 'default' => 'rounded'],
    ],

    'home' => [
        'hero_title' => ['type' => 'string', 'default' => null],
        'hero_subtitle' => ['type' => 'string', 'default' => null],
        'cta_text' => ['type' => 'string', 'default' => 'تصفّح الأحجيات'],
        'show_featured_season' => ['type' => 'boolean', 'default' => true],
        'show_categories' => ['type' => 'boolean', 'default' => true],
        'show_leaderboard' => ['type' => 'boolean', 'default' => true],
        'show_challenges' => ['type' => 'boolean', 'default' => true],
    ],

    'navigation' => [
        'show_seasons_link' => ['type' => 'boolean', 'default' => true],
        'show_puzzles_link' => ['type' => 'boolean', 'default' => true],
        'show_leaderboard_link' => ['type' => 'boolean', 'default' => true],
        'show_challenges_link' => ['type' => 'boolean', 'default' => true],
        'show_gem_balance' => ['type' => 'boolean', 'default' => true],
    ],

    'contact' => [
        'whatsapp' => ['type' => 'url', 'default' => null],
        'instagram' => ['type' => 'url', 'default' => null],
        'facebook' => ['type' => 'url', 'default' => null],
        'x_twitter' => ['type' => 'url', 'default' => null],
        'telegram' => ['type' => 'url', 'default' => null],
        'youtube' => ['type' => 'url', 'default' => null],
        'tiktok' => ['type' => 'url', 'default' => null],
    ],

    'seo' => [
        'meta_title' => ['type' => 'string', 'default' => null],
        'meta_description' => ['type' => 'text', 'default' => 'منصة ألغاز وتحديات ذهنية عربية بنظام مكافآت الجواهر.'],
        'meta_keywords' => ['type' => 'string', 'default' => null],
        'indexing_enabled' => ['type' => 'boolean', 'default' => true],
        'twitter_card_type' => ['type' => 'string', 'default' => 'summary_large_image'],
    ],

    'access' => [
        'allow_registration' => ['type' => 'boolean', 'default' => true],
        'show_registration_cta' => ['type' => 'boolean', 'default' => true],
    ],

    'announcement' => [
        'enabled' => ['type' => 'boolean', 'default' => false],
        'text' => ['type' => 'string', 'default' => null],
        'type' => ['type' => 'string', 'default' => 'info'],
        'url' => ['type' => 'url', 'default' => null],
        'cta_label' => ['type' => 'string', 'default' => null],
    ],

    'footer' => [
        'description' => ['type' => 'text', 'default' => null],
        'copyright_text' => ['type' => 'string', 'default' => null],
        'show_social_links' => ['type' => 'boolean', 'default' => true],
        'show_legal_links' => ['type' => 'boolean', 'default' => true],
    ],

];