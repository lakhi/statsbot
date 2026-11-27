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
 *
 * CITATIONS NEVER ENTER THE ANSWER TEXT. The salient and non-salient arms differ
 * only in whether displaySources() runs, so the text of an answer must not
 * carry its sources - if it did, the non-salient arm would show them in the
 * prose. (Until the three-arm study this class appended a "_Source: ..._" line.)
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
     * The no-rag arm gets the base prompt alone; both RAG arms get the base plus
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

        //The id and the text, nothing else. Titles, lecture and slide numbers
        //would put "Vorlesung 14" in front of the model in BOTH RAG arms, leaving
        //the prompt instruction as the only thing that keeps it out of a
        //non-salient answer. The salient arm loses nothing: its chips are looked
        //up from the hit by id (parse()), never taken from the model.
        foreach ($hits as $h) {
            $lines[] = "[{$h['id']}]";
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
     * `sources` is every valid citation, in the model's order and uncapped - what
     * the answer drew on, whatever the arm displays. displaySources() decides
     * what a salient-arm student sees.
     *
     * @param  array<int,array<string,mixed>>  $hits
     * @return array{answer:string,sources:array<int,array{id:string,label:string,score:float}>}
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
                    'label' => $byId[$id]['label'],
                    'score' => $byId[$id]['score'],
                ] + (isset($byId[$id]['cite']) ? ['cite' => $byId[$id]['cite']] : []);
            } else {
                Log::warning('TutorPrompt: model cited an id that was not retrieved', ['id' => $id]);
            }
        }

        return [
            'answer' => $answer,
            'sources' => $sources,
        ];
    }

    /**
     * The citation chips a salient-arm student sees: one per distinct label,
     * highest retrieval score first, at most config('tutor.max_citations').
     *
     * Deduplicating by label rather than id matters once the corpus is slides:
     * two chunks of "Vorlesung 14, Folie 12" are one source to a student, and
     * showing it twice would only look like padding.
     *
     * An empty result is meaningful - the salient arm then shows its "no
     * matching course material" note.
     *
     * Each chip also carries the label's parts (`cite`: course, doc, topic, pos)
     * when the index has them, so the UI can shorten the topic alone; the full
     * label is always present and is what dedupe and history key on.
     *
     * @param  array<int,array{id:string,label:string,score:float,cite?:array<string,?string>}>  $sources
     * @return array<int,array{id:string,label:string,cite?:array<string,?string>}>
     */
    public static function displaySources(array $sources): array
    {
        $max = max(1, (int) config('tutor.max_citations'));

        //usort is stable, so equal scores keep the model's citation order
        usort($sources, static fn ($a, $b) => $b['score'] <=> $a['score']);

        $shown = [];
        foreach ($sources as $s) {
            if (count($shown) >= $max) {
                break;
            }
            $shown[$s['label']] ??= ['id' => $s['id'], 'label' => $s['label']]
                + (isset($s['cite']) ? ['cite' => $s['cite']] : []);
        }

        return array_values($shown);
    }
}
