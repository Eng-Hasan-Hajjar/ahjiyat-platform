<?php

namespace App\Services\Operations;

use Illuminate\Database\Migrations\Migrator;
use Throwable;

/**
 * E25 - حالة الترحيلات للقراءة فقط: ما هو معلّق (ملف موجود لم يُنفَّذ) وما هو «يتيم» (نُفِّذ بالقاعدة ولا ملف له بالكود الحالي).
 * اليتيم هو العَرَض الكلاسيكي لـ«نشر إصدار أقدم فوق قاعدة أحدث» - أي تراجع عن الكود بعد ترحيل (انظر docs/release-engineering.md).
 * لا يستدعي migrate ولا يكتب شيئًا: الـRepository يقرأ جدول migrations فقط.
 */
final class MigrationState
{
    public function __construct(private readonly Migrator $migrator) {}

    /**
     * @return array{repository: bool, files: int, ran: int, pending: list<string>, orphans: list<string>, paths: array<string, string>}
     */
    public function inspect(): array
    {
        $paths = array_values(array_unique(array_merge([database_path('migrations')], $this->migrator->paths())));
        $files = $this->migrator->getMigrationFiles($paths);

        $state = ['repository' => false, 'files' => count($files), 'ran' => 0, 'pending' => array_keys($files), 'orphans' => [], 'paths' => $files];

        try {
            $repository = $this->migrator->getRepository();

            if (! $repository->repositoryExists()) {
                return $state;
            }

            $ran = $repository->getRan();
        } catch (Throwable) {
            return $state;
        }

        $state['repository'] = true;
        $state['ran'] = count($ran);
        $state['pending'] = array_values(array_diff(array_keys($files), $ran));
        $state['orphans'] = array_values(array_diff($ran, array_keys($files)));

        return $state;
    }
}
