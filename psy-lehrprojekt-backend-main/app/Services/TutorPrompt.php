<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * Builds the message array sent to Azure, and takes the two-layer answer apart
 * again on the way back.
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
 * LAYER SEPARATION is enforced by parsing JSON, not by asking for headings. A
 * formatting slip in prose merges the layers, and a merged layer presents the
 * model's own knowledge as the course's position - the worst error this tool can
 * make. See parse(), which additionally refuses to emit a materials layer when
 * nothing was retrieved, whatever the model claims.
 */
class TutorPrompt
{
    /**
     * @param  array<int,array{role:string,content:string}>  $messages
     * @param  array<int,array<string,mixed>>  $hits
     * @return array<int,array{role:string,content:string}>
     */
    public static function assemble(array $messages, array $hits): array
    {
        $out = [[
            'role' => 'system',
            'content' => (string) config('tutor.system_prompt'),
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

    /** @param array<int,array<string,mixed>> $hits */
    private static function renderMaterials(array $hits): string
    {
        $lines = [
            'Course materials retrieved for the current question. These are excerpts from',
            "the student's own course notes. Use them ONLY for from_materials, cite them by",
            'the id in brackets, and do not treat them as something the student said.',
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
                        'from_materials' => [
                            'type' => ['string', 'null'],
                            'description' => 'What the provided excerpts say. Null if none address the question.',
                        ],
                        'from_general' => [
                            'type' => 'string',
                            'description' => "The tutor's own explanation. Always present.",
                        ],
                        'citations' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                            'description' => 'Ids of excerpts actually used in from_materials.',
                        ],
                    ],
                    'required' => ['from_materials', 'from_general', 'citations'],
                    'additionalProperties' => false,
                ],
            ],
        ];
    }

    /**
     * Split the model's reply into its two layers.
     *
     * @param  array<int,array<string,mixed>>  $hits
     * @return array{from_materials:?string,from_general:string,sources:array<int,array<string,mixed>>,content:string}
     */
    public static function parse(?string $raw, array $hits): array
    {
        $raw = (string) $raw;
        $materials = null;
        $general = $raw;
        $cited = [];

        $decoded = json_decode($raw, true);
        if (is_array($decoded) && array_key_exists('from_general', $decoded)) {
            $materials = isset($decoded['from_materials']) && is_string($decoded['from_materials'])
                ? trim($decoded['from_materials'])
                : null;
            $general = is_string($decoded['from_general']) ? trim($decoded['from_general']) : '';
            $cited = is_array($decoded['citations'] ?? null) ? $decoded['citations'] : [];
        } else {
            // Not JSON - the api-version may not support structured output, or the
            // model ignored it. Fail SAFE: everything becomes the general layer, so
            // nothing is ever misattributed to the course.
            Log::info('TutorPrompt: reply was not structured JSON, treating as general');
        }

        if ($materials === '') {
            $materials = null;
        }

        // Structural guarantee: no retrieved passages means no materials layer,
        // whatever the model asserts. This is what keeps a hallucinated citation
        // from being presented to a student as the course's position.
        if ($hits === []) {
            $materials = null;
            $cited = [];
        }

        // Drop citations to ids that were never supplied.
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

        // A materials layer with no surviving citation cannot be shown as sourced.
        if ($materials !== null && $sources === []) {
            Log::warning('TutorPrompt: materials layer had no valid citation, demoting to general');
            $general = trim($materials."\n\n".$general);
            $materials = null;
        }

        return [
            'from_materials' => $materials,
            'from_general' => $general,
            'sources' => $sources,
            'content' => self::render($materials, $general, $sources),
        ];
    }

    /**
     * Flatten the layers into the single string older clients expect, and into
     * history.received. Keeps the labels so the transcript is still readable as
     * two layers when analysed later.
     *
     * @param  array<int,array<string,mixed>>  $sources
     */
    public static function render(?string $materials, string $general, array $sources): string
    {
        $parts = [];

        if ($materials !== null) {
            $refs = implode(', ', array_map(
                static fn ($s) => $s['heading'].' (p. '.$s['page_start'].')',
                $sources
            ));
            $parts[] = "**From your course materials**\n\n".$materials
                .($refs !== '' ? "\n\n_Source: {$refs}_" : '');
        } else {
            $parts[] = '_'.config('tutor.no_material_note').'_';
        }

        $parts[] = "**Tutor explanation**\n\n".$general;

        return implode("\n\n---\n\n", $parts);
    }
}
