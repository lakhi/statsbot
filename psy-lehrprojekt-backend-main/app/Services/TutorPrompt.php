<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * Builds the message array sent to Azure, and takes the answer apart again on
 * the way back.
 *
 * Two decisions here are load-bearing and easy to undo by accident.
 *
 * MESSAGE ORDER. The system prompt goes first and the retrieved passages go
 * LAST, as their own message immediately before the student's current turn.
 * That is the opposite of the intuitive layout, for two reasons:
 *
 *   - Prompt caching keys on a stable prefix. System prompt + prior conversation
 *     only ever grows at the end, so it caches. Passages change every turn; put
 *     them near the front and the cache is invalidated on every single request.
 *
 *   - The passages must not be appended to the student's own message. api.php
 *     persists $lastSentMessage['content'] to history.sent, which is the corpus
 *     the thesis analyses. Mixing injected context into it would corrupt that
 *     silently and unrecoverably.
 *
 * ARM, NOT HITS, SELECTS THE PROMPT. assemble() takes $grounded - the student's
 * trial arm - separately from $hits, the passages this particular turn found.
 * The materials block is attached on the former. Attaching it on the latter
 * would rewrite the cacheable prefix every time a question fell below
 * RAG_MIN_SCORE, and would give a student a tutor whose instructions silently
 * changed from turn to turn.
 *
 * The answer is ONE coherent explanation; citations remain a separate field so
 * grounding stays measurable. See config/tutor.php for what that trade costs.
 */
class TutorPrompt
{
    /**
     * @param  array<int,array{role:string,content:string}>  $messages
     * @param  array<int,array<string,mixed>>  $hits
     * @return array<int,array{role:string,content:string}>
     */
    public static function assemble(array $messages, array $hits, bool $grounded = false): array
    {
        $out = [[
            'role' => 'system',
            'content' => self::systemPrompt($grounded),
        ]];

        $current = array_pop($messages);

        foreach ($messages as $m) {
            $out[] = ['role' => $m['role'], 'content' => $m['content']];
        }

        if ($hits !== []) {
            $out[] = ['role' => 'system', 'content' => self::renderMaterials($hits)];
        }

        if ($current !== null) {
            $out[] = ['role' => $current['role'], 'content' => $current['content']];
        }

        return $out;
    }

    /**
     * The no-rag arm gets the base prompt alone; the rag arm gets the base plus
     * the materials block. Everything else is identical between the arms, which
     * is what makes the contrast auditable.
     */
    public static function systemPrompt(bool $grounded): string
    {
        $base = (string) config('tutor.system_prompt_base');

        if (! $grounded) {
            return $base;
        }

        return $base."\n\n".(string) config('tutor.system_prompt_materials');
    }

    /** @param array<int,array<string,mixed>> $hits */
    private static function renderMaterials(array $hits): string
    {
        $lines = [
            'Course materials retrieved for the current question. These are excerpts from',
            "the student's own course notes. Cite them by the id in brackets. They are",
            'context, not something the student said.',
            '',
        ];

        foreach ($hits as $h) {
            $pages = $h['page_start'] === $h['page_end']
                ? "p. {$h['page_start']}"
                : "pp. {$h['page_start']}-{$h['page_end']}";

            $lines[] = "[{$h['id']}] {$h['heading']} — {$h['doc_title']}, {$pages}";
            $lines[] = $h['text'];
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    /** Structured-output schema. Null when disabled, so the caller omits the key. */
    public static function responseFormat(): ?array
    {
        if (! config('tutor.structured_output')) {
            return null;
        }

        return [
            'type' => 'json_schema',
            'json_schema' => [
                'name' => 'tutor_answer',
                'strict' => true,
                'schema' => [
                    'type' => 'object',
                    'properties' => [
                        'answer' => [
                            'type' => 'string',
                            'description' => 'The complete explanation, as one coherent answer.',
                        ],
                        'citations' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                            'description' => 'Ids of excerpts actually drawn on. Empty when none were.',
                        ],
                    ],
                    'required' => ['answer', 'citations'],
                    'additionalProperties' => false,
                ],
            ],
        ];
    }

    /**
     * Pull the answer and its citations out of the model's reply.
     *
     * @param  array<int,array<string,mixed>>  $hits
     * @return array{answer:string,sources:array<int,array<string,mixed>>,content:string}
     */
    public static function parse(?string $raw, array $hits): array
    {
        $raw = (string) $raw;
        $answer = $raw;
        $cited = [];

        $decoded = json_decode($raw, true);
        if (is_array($decoded) && array_key_exists('answer', $decoded)) {
            $answer = is_string($decoded['answer']) ? trim($decoded['answer']) : '';
            $cited = is_array($decoded['citations'] ?? null) ? $decoded['citations'] : [];
        } else {
            // Not JSON - the api-version may not support structured output, or the
            // model ignored it. The prose is still a usable answer; it simply
            // carries no citations, so the turn records as ungrounded.
            Log::info('TutorPrompt: reply was not structured JSON, treating as plain answer');
        }

        // Nothing retrieved means nothing citable, whatever the model asserts.
        if ($hits === []) {
            $cited = [];
        }

        // Drop citations to ids that were never supplied. A cited id that was not
        // retrieved is the model inventing a source, and it must not reach a
        // student as a chip implying the course said so.
        $byId = [];
        foreach ($hits as $h) {
            $byId[$h['id']] = $h;
        }
        $sources = [];
        foreach (array_unique($cited) as $id) {
            if (isset($byId[$id])) {
                $sources[] = [
                    'id' => $id,
                    'heading' => $byId[$id]['heading'],
                    'doc_title' => $byId[$id]['doc_title'],
                    'page_start' => $byId[$id]['page_start'],
                    'page_end' => $byId[$id]['page_end'],
                    'score' => $byId[$id]['score'],
                ];
            } else {
                Log::warning('TutorPrompt: model cited an id that was not retrieved', ['id' => $id]);
            }
        }

        return [
            'answer' => $answer,
            'sources' => $sources,
            'content' => self::render($answer, $sources),
        ];
    }

    /**
     * What gets persisted to history.received and shown to the student.
     *
     * The answer is already one coherent piece of prose, so this appends only a
     * source line - the corpus keeps a readable record of which passages the
     * turn drew on without the chips the UI renders separately.
     *
     * @param  array<int,array<string,mixed>>  $sources
     */
    public static function render(string $answer, array $sources): string
    {
        if ($sources === []) {
            return $answer;
        }

        $refs = implode(', ', array_map(
            static fn ($s) => $s['heading'].' (p. '.$s['page_start'].')',
            $sources
        ));

        return trim($answer)."\n\n_Source: {$refs}_";
    }
}
