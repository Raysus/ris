<?php

namespace App\Console\Commands;

use App\Models\Exam;
use App\Services\ExamSubExamService;
use Illuminate\Console\Command;

class SyncExamSubVariants extends Command
{
    protected $signature = 'exams:sync-sub-variants
                            {--lab= : UUID del laboratorio (opcional)}
                            {--from-json : Migra sub_exams JSON existentes a la tabla sub_exams}';

    protected $description = 'Sincroniza variantes/sub-exámenes FONASA en la tabla sub_exams';

    public function handle(): int
    {
        $labId = $this->option('lab');

        if ($this->option('from-json')) {
            $query = Exam::query()->where('is_active', true);
            if ($labId) {
                $query->where('laboratory_id', $labId);
            }

            $migrated = 0;
            foreach ($query->cursor() as $exam) {
                $json = $exam->getAttributes()['sub_exams'] ?? null;
                if (! is_array($json) || $json === []) {
                    continue;
                }
                ExamSubExamService::syncFromItems($exam, $json);
                $migrated++;
            }
            $this->info("Migrados desde JSON: {$migrated} exámenes.");
        }

        $count = ExamSubExamService::applyPredefinedVariants($labId ?: null);
        $this->info("Variantes FONASA aplicadas en {$count} exámenes.");

        return self::SUCCESS;
    }
}
