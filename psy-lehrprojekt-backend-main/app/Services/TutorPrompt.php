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
        $system = (string) config('tutor.system_prompt');
        if (self::mode() === 'json_object') {
            $system .= "\n".config('tutor.json_instruction');
        }

        $out = [['role' => 'system', 'content' => $system]];

        $current = array_pop($messages);

        foreach ($messages as $m) {
            $out[] = ['role' => $m['role'], 'content' => $m['content']];
        }

        // Passages go in a USER message, not a second system one. gpt-oss silently
        // DROPS a second system message - grounding measured 0% on the gold set until
        // this changed - and Foundry rejects the 'developer' role outright (HTTP 422).
        // A separate user turn is the only shape that works on every model tried while
        // keeping both invariants above intact: the cacheable prefix is untouched, and
        // the student's own words stay their own final message for history.sent.
        if ($hits !== []) {
            $out[] = ['role' => 'user', 'content' => self::renderMaterials($hits)];
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

    /**
     * Normalised structured-output mode: json_schema | json_object | off.
     *
     * Accepts the legacy booleans too, so an existing .env carrying
     * TUTOR_STRUCTURED_OUTPUT=true keeps its previous behaviour.
     */
    private static function mode(): string
    {
        $m = config('tutor.structured_output');

        if ($m === true || $m === 'true') {
            return 'json_schema';
        }
        if ($m === false || $m === 'false' || $m === null || $m === '') {
            return 'off';
        }

        return in_array($m, ['json_schema', 'json_object'], true) ? $m : 'off';
    }

    /** Structured-output request. Null when disabled, so the caller omits the key. */
    public static function responseFormat(): ?array
    {
        $mode = self::mode();

        if ($mode === 'off') {
            return null;
        }

        // Open-weight models on Foundry REFUSE json_schema with an HTTP 400 - the
        // request fails outright rather than degrading - so they get plain JSON mode
        // and the shape is asked for in the prompt instead.
        if ($mode === 'json_object') {
            return ['type' => 'json_object'];
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
        $raw = trim((string) $raw);

        // json_object mode carries no shape guarantee, and models habitually wrap the
        // object in a ``` fence. json_decode rejects that, which would silently demote
        // a perfectly good two-layer answer into the prose fallback.
        if (str_starts_with($raw, '```')) {
            $raw = trim((string) preg_replace('/\A```[a-zA-Z]*\s*|\s*```\z/', '', $raw));
        }

        $materials = null;
        $general = $raw;
        $cited = [];

        $decoded = json_decode($raw, true);

        // json_object mode is not schema-enforced, and this model emits literal
        // newlines inside string values often enough to matter - measured at 2-5
        // replies in 16 on mistral-small-2503, every one of them failing with
        // "Unterminated string". Escaping control characters that occur INSIDE a
        // string literal recovers exactly those, and is a no-op on valid JSON.
        if (! is_array($decoded)) {
            $decoded = json_decode(self::repairControlChars($raw), true);
        }

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
     * Escape raw control characters that appear inside JSON string literals.
     *
     * Deliberately character-by-character rather than a regex: a regex cannot tell
     * a newline inside a string from one between keys, and escaping the latter would
     * corrupt otherwise-valid JSON. Tracks string state and backslash escapes so an
     * already-escaped sequence is left alone.
     */
    private static function repairControlChars(string $raw): string
    {
        $out = '';
        $inString = false;
        $escaped = false;

        for ($i = 0, $n = strlen($raw); $i < $n; $i++) {
            $ch = $raw[$i];

            if ($escaped) {
                $out .= $ch;
                $escaped = false;

                continue;
            }

            if ($inString && $ch === '\\') {
                $out .= $ch;
                $escaped = true;

                continue;
            }

            if ($ch === '"') {
                $inString = ! $inString;
                $out .= $ch;

                continue;
            }

            if ($inString) {
                $out .= match ($ch) {
                    "\n" => '\\n',
                    "\r" => '\\r',
                    "\t" => '\\t',
                    default => $ch,
                };

                continue;
            }

            $out .= $ch;
        }

        return $out;
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
