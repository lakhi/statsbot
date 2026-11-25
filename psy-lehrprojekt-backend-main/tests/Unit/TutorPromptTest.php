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
        $raw = json_encode([
            'answer' => 'A p-value is P(T >= c | H0).',
            'citations' => ['hyptest-003'],
        ]);

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
        $parsed = TutorPrompt::parse(json_encode([
            'answer' => 'A p-value is P(T >= c | H0).',
            'citations' => ['hyptest-003'],
        ]), $this->hits());

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
        $raw = json_encode([
            'answer' => 'your notes say ...',
            'citations' => ['hyptest-003'],
        ]);

        $parsed = TutorPrompt::parse($raw, []);

        $this->assertSame([], $parsed['sources'],
            'a citation with no retrieved passage is the model inventing a source');
        $this->assertSame('your notes say ...', $parsed['answer']);
    }

    public function test_a_citation_to_an_unretrieved_id_is_dropped(): void
    {
        $raw = json_encode([
            'answer' => 'an answer',
            'citations' => ['hyptest-003', 'hyptest-999'],
        ]);

        $parsed = TutorPrompt::parse($raw, $this->hits());

        $this->assertCount(1, $parsed['sources']);
        $this->assertSame('hyptest-003', $parsed['sources'][0]['id']);
    }

    public function test_non_json_reply_is_still_a_usable_answer(): void
    {
        $parsed = TutorPrompt::parse('just prose, no JSON', $this->hits());

        $this->assertSame('just prose, no JSON', $parsed['answer']);
        $this->assertSame([], $parsed['sources'], 'prose carries no citations, so the turn is ungrounded');
    }

    public function test_response_format_can_be_disabled(): void
    {
        config(['tutor.structured_output' => false]);
        $this->assertNull(TutorPrompt::responseFormat());

        config(['tutor.structured_output' => true]);
        $format = TutorPrompt::responseFormat();
        $this->assertSame(
            ['answer', 'citations'],
            $format['json_schema']['schema']['required']
        );
    }
}
