<?php

namespace App\Console\Commands;

use App\Models\GlossaryTerm;
use App\Models\Setting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class GlossaryImport extends Command
{
    protected $signature = 'glossary:import {--file= : Glossary JSON (default database/data/glossary.json)}';

    protected $description = 'Import the hadith-grading glossary (lay explanations, verbatim from islamqa). The intro is kept in the glossary_intro setting.';

    public function handle(): int
    {
        $file = $this->option('file') ?: database_path('data/glossary.json');
        $data = is_file($file) ? json_decode(File::get($file), true) : null;

        if (! is_array($data) || empty($data['terms'])) {
            $this->error('The glossary file is missing or empty.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($data) {
            foreach ($data['terms'] as $position => $term) {
                GlossaryTerm::query()->updateOrCreate(['key' => $term['term']], [
                    'label_ar' => $term['labelAr'],
                    'label_en' => $term['labelEn'],
                    'level' => $term['level'],
                    'entry_ar' => $term['ar'] ?? null,
                    'entry_en' => $term['en'] ?? null,
                    'position' => $position,
                ]);
            }

            Setting::put('glossary_intro', $data['intro'] ?? ['ar' => null, 'en' => null]);
        });

        $this->info(count($data['terms']).' glossary terms imported.');

        return self::SUCCESS;
    }
}
