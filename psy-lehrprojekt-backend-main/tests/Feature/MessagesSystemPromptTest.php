<?php

namespace Tests\Feature;

use App\Models\History;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * POST /messages sends Azure a system prompt asking for LaTeX ahead of the
 * student's conversation, so formulas come back in a form the chat can typeset.
 *
 * Deliberately written to hold on rag-pilot as well, which builds the same
 * shape through TutorPrompt::assemble() with a longer prompt: it checks that the
 * system message asks for LaTeX, not its exact wording.
 */
class MessagesSystemPromptTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // AuthenticateStudent reads Shibboleth attributes off $_SERVER.
        $_SERVER['uid'] = 'u:test01';
        $_SERVER['givenName'] = 'Test';
        $_SERVER['sn'] = 'Student';

        $student = new Student;
        $student->uid = 'u:test01';
        $student->firstname = 'Test';
        $student->lastname = 'Student';
        $student->token_limit = 10000;
        $student->token_left = 10000;
        $student->activated = true;

        // main's students migration still declares matnr and lv NOT NULL; the one
        // reconciled against the live schema on rag-pilot dropped them.
        foreach (['matnr' => '00000000', 'lv' => 'test'] as $column => $value) {
            if (Schema::hasColumn('students', $column)) {
                $student->{$column} = $value;
            }
        }
        $student->save();

        Http::fake([
            '*' => Http::response([
                'choices' => [['message' => ['content' => 'The mean is \\(\\bar{x}\\).']]],
                'usage' => ['prompt_tokens' => 40, 'completion_tokens' => 10, 'total_tokens' => 50],
            ]),
        ]);
    }

    protected function tearDown(): void
    {
        unset($_SERVER['uid'], $_SERVER['givenName'], $_SERVER['sn']);
        parent::tearDown();
    }

    public function test_a_latex_system_prompt_goes_first_and_the_conversation_follows_unchanged(): void
    {
        $conversation = [
            ['role' => 'user', 'content' => 'What is a t-test?'],
            ['role' => 'assistant', 'content' => 'It compares means.'],
            ['role' => 'user', 'content' => 'And the formula?'],
        ];

        $this->postJson('/api/messages', ['messages' => $conversation, 'started' => 1763900000000])
            ->assertOk();

        Http::assertSent(function (Request $request) use ($conversation) {
            $sent = $request['messages'];

            return $sent[0]['role'] === 'system'
                && str_contains($sent[0]['content'], 'LaTeX')
                && array_slice($sent, 1) === $conversation;
        });
    }

    public function test_the_prompt_never_reaches_the_stored_conversation(): void
    {
        $this->postJson('/api/messages', [
            'messages' => [['role' => 'user', 'content' => 'And the formula?']],
            'started' => 1763900000000,
        ])->assertOk();

        // history.sent is the corpus the thesis analyses: it must stay what the student typed
        $this->assertSame('And the formula?', History::sole()->sent);
    }
}
