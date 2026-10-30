<?php

namespace App\Translation;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

/**
 * Translates Arabic Islamic text to English, item by item. It receives {"items": [...]} and returns the same number of
 * translations in the same order, so structure (paragraphs, quotes) is rebuilt by us and never by the model.
 */
class TranslatorAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function __construct(private string $kind = 'text') {}

    public function instructions(): string
    {
        $what = $this->kind === 'titles'
            ? 'short fatwa titles (the headline of a question and answer)'
            : 'passages of a fatwa (a question or its answer), possibly containing Quran verses, hadith and quotations from scholars';

        return <<<TXT
        You translate Arabic Islamic texts into clear, faithful English. The items are {$what}.

        Rules:
        - Translate only what is written. Never add, explain, soften, summarise or leave anything out.
        - Keep the meaning and the register of the original. Do not give your own ruling or opinion.
        - Render honorifics naturally (ﷺ as "peace be upon him", رضي الله عنه as "may Allah be pleased with him").
        - Keep established Islamic terms (zakat, wudu, salah, hadith) and Allah's name as Allah.
        - Quote Quran verses and hadith in plain English as part of the sentence; do not invent references.
        - The input is a JSON object {"items": [...]}. Return exactly one translation per item, in the same order.
        - An item that is already English, numbers only, or a symbol stays as it is.
        TXT;
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'translations' => $schema->array()->items($schema->string())->required(),
        ];
    }
}
