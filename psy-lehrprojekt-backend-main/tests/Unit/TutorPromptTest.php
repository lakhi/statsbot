<?php

namespace Tests\Unit;

use App\Services\TutorPrompt;
use Tests\TestCase;

class TutorPromptTest extends TestCase
{
    /** @return array<int,array<string,mixed>> */
    private function hits(): array
    {
        return [[
            'id' => 'hyptest-003',
            'heading' => '2 p-Values',
            'label' => '2 p-Values, p. 2',
            'doc_title' => 'Lecture Notes: Hypothesis Testing',
            'page_start' => 2,
            'page_end' => 2,
            'text' => '## 2 p-Values ...',
            'score' => 0.47,
        ]];
    }

    public function test_system_prompt_is_first_and_materials_sit_before_the_current_turn(): void
    {
        $messages = [
            ['role' => 'user', 'content' => 'first question'],
            ['role' => 'assistant', 'content' => 'first answer'],
            ['role' => 'user', 'content' => 'what is a p-value?'],
        ];

        $out = TutorPrompt::assemble($messages, $this->hits(), true);

        $this->assertSame('system', $out[0]['role']);
        $this->assertStringContainsString('StatsBot', $out[0]['content']);

        // passages immediately before the student's current turn, so the
        // cacheable prefix (system + prior conversation) is never disturbed
        $last = count($out) - 1;
        $this->assertSame('what is a p-value?', $out[$last]['content']);
        $this->assertStringContainsString('hyptest-003', $out[$last - 1]['content']);
    }

    public function test_excerpts_reach_the_model_as_id_and_text_only(): void
    {
        // a title or slide number in front of the model, in BOTH RAG arms, would
        // leave only the prompt keeping "Vorlesung 14" out of a non-salient answer
        $hits = $this->hits();
        $hits[0]['heading'] = 'Vorlesung 14 › Voraussetzungen';
        $hits[0]['doc_title'] = 'Vorlesung 14: t-Test I';
        $hits[0]['label'] = 'Vorlesung 14: t-Test I, Folie 12';

        $out = TutorPrompt::assemble([['role' => 'user', 'content' => 'q']], $hits, true);
        $materials = $out[1]['content'];

        $this->assertStringContainsString("[hyptest-003]\n## 2 p-Values ...", $materials);
        $this->assertStringNotContainsString('Vorlesung', $materials);
        $this->assertStringNotContainsString('Folie', $materials);
    }

    public function test_the_two_arms_differ_by_exactly_the_materials_block(): void
    {
        $control = TutorPrompt::systemPrompt(false);
        $grounded = TutorPrompt::systemPrompt(true);

        // the contrast has to be one contiguous appended block: everything the
        // control arm is told, the rag arm is told identically
        $this->assertStringStartsWith($control, $grounded);
        $this->assertSame(
            "\n\n".config('tutor.system_prompt_materials'),
            substr($grounded, strlen($control))
        );
    }

    public function test_the_control_prompt_never_mentions_course_materials(): void
    {
        // a control arm that knows about materials can apologise for not having
        // them, which would mark the arm on the very first turn
        $this->assertStringNotContainsStringIgnoringCase(
            'course materials',
            TutorPrompt::systemPrompt(false)
        );
    }

    public function test_the_arm_selects_the_prompt_even_when_nothing_was_retrieved(): void
    {
        // below the similarity floor the rag arm still gets its own prompt -
        // otherwise the cacheable prefix would flip whenever a question missed
        $out = TutorPrompt::assemble([['role' => 'user', 'content' => 'q']], [], true);

        $this->assertSame(TutorPrompt::systemPrompt(true), $out[0]['content']);
        $this->assertCount(2, $out, 'no materials message when nothing was retrieved');
    }

    public function test_no_materials_message_when_nothing_was_retrieved(): void
    {
        $out = TutorPrompt::assemble([['role' => 'user', 'content' => 'q']], [], false);

        $this->assertCount(2, $out);
        $this->assertSame('system', $out[0]['role']);
        $this->assertSame('user', $out[1]['role']);
    }

    public function test_student_message_is_never_modified(): void
    {
        $messages = [['role' => 'user', 'content' => 'what is a p-value?']];

        $out = TutorPrompt::assemble($messages, $this->hits(), true);

        $this->assertSame('what is a p-value?', $out[count($out) - 1]['content']);
        $this->assertSame([['role' => 'user', 'content' => 'what is a p-value?']], $messages);
    }

    public function test_parses_one_answer_and_resolves_citations(): void
    {
        $raw = "A p-value is P(T >= c | H0).\n\nCITATIONS: hyptest-003";

        $parsed = TutorPrompt::parse($raw, $this->hits());

        $this->assertSame('A p-value is P(T >= c | H0).', $parsed['answer']);
        $this->assertCount(1, $parsed['sources']);
        $this->assertSame('hyptest-003', $parsed['sources'][0]['id']);
        $this->assertSame('2 p-Values, p. 2', $parsed['sources'][0]['label']);
    }

    public function test_the_answer_text_never_carries_its_sources(): void
    {
        // the salient and non-salient arms differ ONLY in whether sources are
        // displayed; a source line in the prose would show them in both
        $parsed = TutorPrompt::parse(
            "A p-value [hyptest-003] is P(T >= c | H0) (hyptest-003).\nCITATIONS: hyptest-003",
            $this->hits()
        );

        $this->assertSame('A p-value is P(T >= c | H0).', $parsed['answer']);
        $this->assertArrayNotHasKey('content', $parsed);
    }

    public function test_display_sources_dedupes_by_label_orders_by_score_and_caps(): void
    {
        config(['tutor.max_citations' => 3]);

        $shown = TutorPrompt::displaySources([
            ['id' => 'a', 'label' => 'Vorlesung 2, Folie 3', 'score' => 0.40],
            ['id' => 'b', 'label' => 'Vorlesung 14, Folie 12', 'score' => 0.61],
            ['id' => 'c', 'label' => 'Vorlesung 14, Folie 12', 'score' => 0.58],
            ['id' => 'd', 'label' => 'Klausur 2023, Aufgabe 4', 'score' => 0.52],
            ['id' => 'e', 'label' => 'Übung 5, Aufgabe 1', 'score' => 0.37],
        ]);

        // the duplicate slide does not use up a slot: three DISTINCT labels
        $this->assertSame([
            ['id' => 'b', 'label' => 'Vorlesung 14, Folie 12'],
            ['id' => 'd', 'label' => 'Klausur 2023, Aufgabe 4'],
            ['id' => 'a', 'label' => 'Vorlesung 2, Folie 3'],
        ], $shown);
    }

    public function test_display_sources_respects_a_lower_cap_and_never_leaks_scores(): void
    {
        config(['tutor.max_citations' => 1]);

        $shown = TutorPrompt::displaySources([
            ['id' => 'a', 'label' => 'A', 'score' => 0.40],
            ['id' => 'b', 'label' => 'B', 'score' => 0.61],
        ]);

        $this->assertSame([['id' => 'b', 'label' => 'B']], $shown);
    }

    public function test_display_sources_of_nothing_is_empty(): void
    {
        // empty is meaningful: the salient arm then shows its "no matching
        // course material" note
        $this->assertSame([], TutorPrompt::displaySources([]));
    }

    public function test_citations_are_impossible_when_nothing_was_retrieved(): void
    {
        $raw = "your notes say ...\n\nCITATIONS: hyptest-003";

        $parsed = TutorPrompt::parse($raw, []);

        $this->assertSame([], $parsed['sources'],
            'a citation with no retrieved passage is the model inventing a source');
        $this->assertSame('your notes say ...', $parsed['answer']);
    }

    public function test_a_citation_to_an_unretrieved_id_is_dropped(): void
    {
        $raw = "an answer\n\nCITATIONS: hyptest-003, hyptest-999";

        $parsed = TutorPrompt::parse($raw, $this->hits());

        $this->assertCount(1, $parsed['sources']);
        $this->assertSame('hyptest-003', $parsed['sources'][0]['id']);
    }

    public function test_a_reply_without_a_citation_line_is_still_a_usable_answer(): void
    {
        $parsed = TutorPrompt::parse('just prose, no citation line', $this->hits());

        $this->assertSame('just prose, no citation line', $parsed['answer']);
        $this->assertSame([], $parsed['sources'], 'nothing cited, so the turn is ungrounded');
    }

    public function test_citations_none_cites_nothing_and_is_removed(): void
    {
        $parsed = TutorPrompt::parse("Kurz gesagt: nein.\n\nCITATIONS: none", $this->hits());

        $this->assertSame('Kurz gesagt: nein.', $parsed['answer']);
        $this->assertSame([], $parsed['sources']);
    }

    /** @return array<string,array{0:string}> */
    public static function citationLineShapes(): array
    {
        // all seen from gpt-5.4-mini in the 27 Nov test run, or close to it
        return [
            'own line' => ["Die Antwort.\nCITATIONS: c8603143, c76373e1"],
            'after a blank line' => ["Die Antwort.\n\nCITATIONS: c8603143, c76373e1\n"],
            'run on after the last sentence' => ['Die Antwort. CITATIONS: c8603143, c76373e1'],
            'bold' => ["Die Antwort.\n\n**CITATIONS:** c8603143, c76373e1"],
            'bracketed ids' => ["Die Antwort.\n\nCITATIONS: [c8603143], [c76373e1]."],
            'lower case' => ["Die Antwort.\n\nCitations: c8603143; c76373e1"],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('citationLineShapes')]
    public function test_the_citation_line_is_found_in_every_shape_the_model_writes(string $raw): void
    {
        $parsed = TutorPrompt::parse($raw, $this->corpusHits());

        $this->assertSame('Die Antwort.', $parsed['answer']);
        $this->assertSame(['c8603143', 'c76373e1'], array_column($parsed['sources'], 'id'));
    }

    public function test_only_a_final_citation_line_counts(): void
    {
        $raw = "Write CITATIONS: like this in a paper?\nNo, use APA.\n\nCITATIONS: none";

        $parsed = TutorPrompt::parse($raw, $this->corpusHits());

        $this->assertSame("Write CITATIONS: like this in a paper?\nNo, use APA.", $parsed['answer']);
        $this->assertSame([], $parsed['sources']);
    }

    public function test_inline_ids_are_removed_from_the_prose_and_count_as_cited(): void
    {
        $raw = "Ein t-Test vergleicht zwei Gruppen [c8603143]. Die ANOVA prüft \\(H_0\\) [c76373e1, c c8803f3].\n\n"
            ."Für zwei Gruppen gilt \\(F=t^2\\) (c8603143).\n\nCITATIONS: c8603143";

        $parsed = TutorPrompt::parse($raw, $this->corpusHits());

        $this->assertSame(
            "Ein t-Test vergleicht zwei Gruppen. Die ANOVA prüft \\(H_0\\).\n\nFür zwei Gruppen gilt \\(F=t^2\\).",
            $parsed['answer']
        );
        // "c c8803f3" is the retrieved id cc8803f3 with a stray space in it
        $this->assertSame(['c8603143', 'c76373e1', 'cc8803f3'], array_column($parsed['sources'], 'id'));
    }

    public function test_a_split_id_on_the_citation_line_is_joined(): void
    {
        $parsed = TutorPrompt::parse("Antwort.\n\nCITATIONS: c c8803f3, c8603143", $this->corpusHits());

        $this->assertSame(['cc8803f3', 'c8603143'], array_column($parsed['sources'], 'id'));
    }

    public function test_brackets_that_are_not_ids_are_left_alone(): void
    {
        $raw = "Das 95%-KI ist [0.34, 1.23] und \\[x\\] bleibt (c ist eine Konstante).\nCITATIONS: none";

        $parsed = TutorPrompt::parse($raw, $this->corpusHits());

        $this->assertSame('Das 95%-KI ist [0.34, 1.23] und \\[x\\] bleibt (c ist eine Konstante).', $parsed['answer']);
    }

    public function test_control_characters_never_reach_the_student(): void
    {
        // what corrupted JSON escapes left in the answer before the plain-text format
        $raw = "H\x00e4ufigkeiten und \x08eta\x1b[0m\ttab stays\r\nline\nCITATIONS: none";

        $parsed = TutorPrompt::parse($raw, $this->corpusHits());

        $this->assertSame("He4ufigkeiten und eta[0m\ttab stays\r\nline", $parsed['answer']);
    }

    public function test_every_arm_is_asked_for_the_citation_line_and_none_for_json(): void
    {
        foreach ([false, true] as $grounded) {
            $prompt = TutorPrompt::systemPrompt($grounded);
            $this->assertStringContainsString('CITATIONS: none', $prompt);
            $this->assertStringNotContainsString('Return JSON', $prompt);
        }
        $materials = TutorPrompt::assemble([['role' => 'user', 'content' => 'q']], $this->hits(), true)[1]['content'];
        $this->assertStringContainsString('CITATIONS line', $materials);
    }

    /** @return array<int,array<string,mixed>> */
    private function corpusHits(): array
    {
        $hit = fn (string $id, string $label, float $score) => [
            'id' => $id, 'heading' => '', 'label' => $label, 'doc_title' => '', 'page_start' => 1,
            'page_end' => 1, 'text' => '...', 'score' => $score,
        ];

        return [
            $hit('c8603143', 'Statistik II · Einheit 1: Varianzanalytische Methoden · Folie 24', 0.61),
            $hit('c76373e1', 'Statistik II · Einheit 1: Varianzanalytische Methoden · Folie 18', 0.58),
            $hit('cc8803f3', 'Statistik I · Vorlesung 14: t-Test · Folie 2', 0.52),
        ];
    }
}
