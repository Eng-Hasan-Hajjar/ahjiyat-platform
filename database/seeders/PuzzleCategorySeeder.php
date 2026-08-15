<?php

namespace Database\Seeders;

use App\Models\PuzzleCategory;
use Illuminate\Database\Seeder;

class PuzzleCategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['name' => 'ألغاز منطقية', 'slug' => 'logic', 'icon' => 'puzzle-piece', 'sort_order' => 1],
            ['name' => 'ألغاز رياضية', 'slug' => 'math', 'icon' => 'calculator', 'sort_order' => 2],
            ['name' => 'ألغاز لغوية', 'slug' => 'language', 'icon' => 'language', 'sort_order' => 3],
            ['name' => 'ثقافة عامة', 'slug' => 'general-knowledge', 'icon' => 'academic-cap', 'sort_order' => 4],
            ['name' => 'ألغاز الصور', 'slug' => 'visual', 'icon' => 'photo', 'sort_order' => 5],
        ];

        foreach ($categories as $category) {
            PuzzleCategory::firstOrCreate(['slug' => $category['slug']], $category);
        }
    }
}
