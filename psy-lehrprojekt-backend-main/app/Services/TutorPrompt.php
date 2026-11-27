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
 * parse() therefore strips the citation line and any "[id]" the model wrote
 * into the prose anyway, and counts those ids as cited.
 *
 * PLAIN TEXT, NOT JSON. The reply is the answer followed by one final line,
 * "CITATIONS: id, id" or "CITATIONS: none". It used to be a strict JSON schema
 * {answer, citations}, but inside a JSON string every umlaut, Greek letter and
 * LaTeX backslash must be escaped, and gpt-5.4-mini got those escapes wrong in
 * a third of its answers ("H\x00e4ufigkeiten", "\x08eta" for \beta). Re-asked as
 * plain text, the same 44 questions came back with none (bake-off, 27 Nov 2026).
 */
class TutorPrompt
{
    /** Characters no answer may contain: C0 controls except tab, newline, CR; and DEL. */
    private const CONTROL = '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u';

    /** Shape of a corpus chunk id ("c" + 7 hex). The model sometimes splits it ("c c8803f3"). */
    private const CHUNK_ID = 'c\s?[0-9a-f]{7}';

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
        //The reminder about the CITATIONS line sits here, right before the
        //student's turn, because the earlier assistant turns the frontend sends
        //back have that line stripped and would otherwise teach the model to drop it.
        $lines = [
            'Course materials retrieved for the current question. These are excerpts from',
            "the student's own course notes, each headed by its id in brackets. They are",
            'context, not something the student said. List the ids of the excerpts you',
            'draw on in your final CITATIONS line, and nowhere else.',
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

    /**
     * Pull the answer and its citations out of the model's reply.
     *
     * The reply is the answer, then a final "CITATIONS: id, id" (or "none") line.
     * Cited are the ids on that line plus any "[id]" the model wrote into the
     * prose, which is removed. A reply without the line is still a usable answer;
     * it is simply grounded only by whatever ids it carried inline.
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
        $byId = [];
        foreach ($hits as $h) {
            $byId[$h['id']] = $h;
        }

        [$answer, $cited] = self::splitCitationLine((string) $raw, array_keys($byId));
        [$answer, $inline] = self::stripInlineIds($answer, array_keys($byId));
        $cited = array_merge($cited, $inline);

        $answer = preg_replace(self::CONTROL, '', $answer, -1, $controls) ?? $answer;
        if ($controls > 0) {
            Log::warning('TutorPrompt: removed control characters from the answer', ['count' => $controls]);
        }
        $answer = trim($answer);

        // Nothing retrieved means nothing citable, whatever the model asserts.
        if ($hits === []) {
            $cited = [];
        }

        // Drop citations to ids that were never supplied. A cited id that was not
        // retrieved is the model inventing a source, and it must not reach a
        // student as a chip implying the course said so.
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
     * Split off the final "CITATIONS: ..." line.
     *
     * Takes the LAST "CITATIONS:" followed by nothing but the rest of its line.
     * The model usually writes it on a line of its own, occasionally runs it on
     * after its final sentence, sometimes in bold or backticks. A mention earlier
     * in the text is left alone.
     *
     * @param  array<int,string>  $hitIds
     * @return array{0:string,1:array<int,string>} the answer without the line, and the ids on it
     */
    private static function splitCitationLine(string $raw, array $hitIds): array
    {
        if (! preg_match('/^(.*)(?:^|(?<=\s))[*_`]{0,2}CITATIONS[*_`]{0,2}[ \t]*:([^\n]*)\s*\z/sui', $raw, $m)) {
            if ($hitIds !== []) {
                Log::info('TutorPrompt: reply has no CITATIONS line');
            }

            return [$raw, []];
        }

        //Ids always contain a digit, which drops "none", "keine" and similar.
        //Two adjacent tokens that join into a retrieved id are one id the model
        //split ("c c8803f3").
        $tokens = preg_split('/[^\w-]+/u', $m[2], -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $ids = [];
        for ($i = 0; $i < count($tokens); $i++) {
            if (isset($tokens[$i + 1]) && in_array($tokens[$i].$tokens[$i + 1], $hitIds, true)) {
                $ids[] = $tokens[$i].$tokens[++$i];
            } elseif (preg_match('/\d/', $tokens[$i])) {
                $ids[] = $tokens[$i];
            }
        }

        return [rtrim($m[1]), $ids];
    }

    /**
     * Remove "[id]" / "[id, id]" / "(id)" groups from the prose and return their ids.
     *
     * The prompt asks for ids on the CITATIONS line only, but the model still
     * writes them inline in about two answers in five. They mean nothing to a
     * student, and in the non-salient arm they would look like source markers.
     * An id written inline is still the model saying it drew on that excerpt, so
     * it counts as cited.
     *
     * @param  array<int,string>  $hitIds
     * @return array{0:string,1:array<int,string>}
     */
    private static function stripInlineIds(string $answer, array $hitIds): array
    {
        $one = implode('|', [...array_map(static fn ($id) => preg_quote($id, '/'), $hitIds), self::CHUNK_ID]);
        $ids = [];

        $stripped = preg_replace_callback(
            "/[ \\t]*[\\[(]\\s*(?:{$one})(?:\\s*[,;]\\s*(?:{$one}))*\\s*[\\])]/u",
            static function (array $m) use (&$ids, $one): string {
                preg_match_all("/{$one}/u", $m[0], $all);
                foreach ($all[0] as $id) {
                    $ids[] = (string) preg_replace('/\s+/', '', $id);
                }

                return '';
            },
            $answer
        );

        return [$stripped ?? $answer, $ids];
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
