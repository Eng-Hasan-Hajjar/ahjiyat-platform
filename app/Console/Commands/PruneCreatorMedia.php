<?php

namespace App\Console\Commands;

use App\Models\CreatorPuzzleRevision;
use App\Models\Puzzle;
use App\Services\Creators\CreatorMediaService;
use Illuminate\Console\Command;

class PruneCreatorMedia extends Command
{
    protected $signature = 'creators:prune-media {--hours=24 : مهلة أمان بالساعات قبل اعتبار الملف يتيمًا} {--dry-run : عرض ما سيُحذف دون حذف}';

    protected $description = 'E28: يحذف صور أحجيات المبدعين اليتيمة (ملفات غير مرتبطة بأي نسخة ولا أحجية). لا يمسّ ملفًا مرتبطًا ولا ملفًا أحدث من مهلة الأمان. صحة النظام لا تعتمد عليه.';

    public function handle(CreatorMediaService $media): int
    {
        $grace = max(1, (int) $this->option('hours'));
        $dry = (bool) $this->option('dry-run');
        $cutoff = now()->subHours($grace)->getTimestamp();
        $directory = trim((string) config('creators.media.directory'), '/');
        $disk = $media->disk();

        $referenced = [];

        CreatorPuzzleRevision::query()->whereNotNull('image_path')->select('id', 'image_path')
            ->chunkById(500, function ($rows) use (&$referenced) {
                foreach ($rows as $row) {
                    $referenced[$row->image_path] = true;
                }
            });

        Puzzle::query()->where('origin', Puzzle::ORIGIN_COMMUNITY)->whereNotNull('image_path')->select('id', 'image_path')
            ->chunkById(500, function ($rows) use (&$referenced) {
                foreach ($rows as $row) {
                    $referenced[$row->image_path] = true;
                }
            });

        $removed = 0;

        foreach ($disk->allFiles($directory) as $path) {
            if (isset($referenced[$path]) || $disk->lastModified($path) > $cutoff) {
                continue;
            }

            if (! $dry) {
                $disk->delete($path);
            }

            $removed++;
        }

        $this->info(($dry ? 'سيُحذف ' : 'حُذف ').$removed.' ملفًا يتيمًا أقدم من '.$grace.' ساعة.');

        return self::SUCCESS;
    }
}
