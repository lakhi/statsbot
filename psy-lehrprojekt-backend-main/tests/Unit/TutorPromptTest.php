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
        $this->assertSame(2, $parsed['sources'][0]['page_start']);
        $this->assertStringContainsString('A p-value is', $parsed['content']);
        $this->assertStringContainsString('2 p-Values', $parsed['content']);
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
