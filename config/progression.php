<?php

// E12: XP الافتراضية للأحجية عندما puzzle.xp_reward = null (بند 164/169).
// صفر صريح (0) على الأحجية نفسها يبقى صفرًا دائمًا - لا علاقة له بهذا الإعداد.
return [
    'default_puzzle_xp' => (int) env('PROGRESSION_DEFAULT_PUZZLE_XP', 10),
];