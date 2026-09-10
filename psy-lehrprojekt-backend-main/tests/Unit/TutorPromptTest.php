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

        $out = TutorPrompt::assemble($messages, $this->hits());

        // Stable cacheable prefix: system, then the conversation so far.
        $this->assertSame('system', $out[0]['role']);
        $this->assertStringContainsString('StatsBot', $out[0]['content']);
        $this->assertSame('first question', $out[1]['content']);
        $this->assertSame('first answer', $out[2]['content']);

        // Volatile passages go last, immediately before the student's turn, so a
        // changing retrieval never invalidates the cached prefix.
        $this->assertSame('system', $out[3]['role']);
        $this->assertStringContainsString('hyptest-003', $out[3]['content']);
        $this->assertStringContainsString('Course materials', $out[3]['content']);

        $last = end($out);
        $this->assertSame('user', $last['role']);
        $this->assertSame('what is a p-value?', $last['content']);
    }

    public function test_no_materials_message_when_nothing_was_retrieved(): void
    {
        $out = TutorPrompt::assemble([['role' => 'user', 'content' => 'hi']], []);

        $this->assertCount(2, $out);
        $this->assertSame('system', $out[0]['role']);
        $this->assertSame('hi', $out[1]['content']);
    }

    public function test_student_message_is_never_modified(): void
    {
        $original = 'what is a p-value?';
        $out = TutorPrompt::assemble([['role' => 'user', 'content' => $original]], $this->hits());

        $last = end($out);
        $this->assertSame($original, $last['content'],
            'passages must not be appended to the student turn - history.sent is persisted from it');
    }

    public function test_parses_the_two_layers_and_resolves_citations(): void
    {
        $raw = json_encode([
            'from_materials' => 'The notes define the p-value as P(T >= c | H0).',
            'from_general' => 'Intuitively, it measures surprise under the null.',
            'citations' => ['hyptest-003'],
        ]);

        $out = TutorPrompt::parse($raw, $this->hits());

        $this->assertSame('The notes define the p-value as P(T >= c | H0).', $out['from_materials']);
        $this->assertSame('Intuitively, it measures surprise under the null.', $out['from_general']);
        $this->assertCount(1, $out['sources']);
        $this->assertSame(2, $out['sources'][0]['page_start']);
        $this->assertStringContainsString('From your course materials', $out['content']);
    }

    public function test_materials_layer_is_impossible_when_nothing_was_retrieved(): void
    {
        // The model claiming a source it was never given is the worst failure mode
        // available to this tool, so it is blocked structurally rather than by prompt.
        $raw = json_encode([
            'from_materials' => 'Your notes say ANOVA partitions variance.',
            'from_general' => 'ANOVA compares group means.',
            'citations' => ['hyptest-999'],
        ]);

        $out = TutorPrompt::parse($raw, []);

        $this->assertNull($out['from_materials']);
        $this->assertSame([], $out['sources']);
        $this->assertStringContainsString(config('tutor.no_material_note'), $out['content']);
    }

    public function test_citation_to_an_unretrieved_id_demotes_the_materials_layer(): void
    {
        $raw = json_encode([
            'from_materials' => 'Something attributed to the notes.',
            'from_general' => 'General explanation.',
            'citations' => ['hyptest-042'],
        ]);

        $out = TutorPrompt::parse($raw, $this->hits());

        $this->assertNull($out['from_materials'], 'unsourceable material must not be shown as sourced');
        $this->assertStringContainsString('Something attributed to the notes.', $out['from_general']);
    }

    public function test_non_json_reply_falls_back_to_the_general_layer(): void
    {
        $out = TutorPrompt::parse('Just prose, no schema honoured.', $this->hits());

        $this->assertNull($out['from_materials']);
        $this->assertSame('Just prose, no schema honoured.', $out['from_general']);
    }

    public function test_response_format_can_be_disabled(): void
    {
        config(['tutor.structured_output' => true]);
        $this->assertSame('json_schema', TutorPrompt::responseFormat()['type']);

        config(['tutor.structured_output' => false]);
        $this->assertNull(TutorPrompt::responseFormat());
    }
}
