<?php

namespace App\Topics;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

/**
 * Proposes, for each source category, the closest topic of the shared tree. The owner reviews every proposal in the admin.
 */
class CategoryMappingAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): string
    {
        return <<<'TXT'
        You file the categories of an Islamic fatwa website into a shared topic tree.

        The input is JSON: {"topics": [{"key", "path"}], "categories": [{"id", "path"}]}. Paths are Arabic, broad to narrow.

        Rules:
        - For every category, choose the single topic whose subject matches it best.
        - Prefer the most specific topic that still covers the whole category. If no specific topic fits, choose the
          nearest broader one. Always choose a topic from the list; never invent a key.
        - Judge by the category's full path, not only its last name.
        - Return one mapping per category: {"category": <category id>, "topic": <topic key>}.
        TXT;
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'mappings' => $schema->array()->items($schema->object([
                'category' => $schema->string()->required(),
                'topic' => $schema->string()->required(),
            ]))->required(),
        ];
    }
}
