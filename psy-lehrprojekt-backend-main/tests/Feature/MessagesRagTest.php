<?php

namespace Tests\Feature;

use App\Models\History;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MessagesRagTest extends TestCase
{
    use RefreshDatabase;

    private string $indexBase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->indexBase = sys_get_temp_dir().'/ragtest-'.uniqid();
        $this->writeIndex();

        config([
            'tutor.rag_enabled' => true,
            'tutor.index_path' => $this->indexBase,
            'tutor.top_k' => 3,
            'tutor.min_score' => 0.35,
            'tutor.structured_output' => true,
            'tutor.embed.endpoint' => 'https://example.invalid',
            'tutor.embed.deployment' => 'test-embed',
            'tutor.embed.model' => 'text-embedding-3-large',
            'tutor.embed.dims' => 3,
            'tutor.embed.timeout' => 5,
        ]);

        // AuthenticateStudent reads Shibboleth attributes off $_SERVER.
        $_SERVER['uid'] = 'u:test01';
        $_SERVER['givenName'] = 'Test';
        $_SERVER['sn'] = 'Student';
    }

    protected function tearDown(): void
    {
        @unlink($this->indexBase.'.f32');
        @unlink($this->indexBase.'.json');
        unset($_SERVER['uid'], $_SERVER['givenName'], $_SERVER['sn']);
        parent::tearDown();
    }

    private function writeIndex(): void
    {
        file_put_contents($this->indexBase.'.f32', pack('g*', 1, 0, 0).pack('g*', 0, 1, 0));
        file_put_contents($this->indexBase.'.json', json_encode([
            'index_version' => 1, 'doc_id' => 'hyptest',
            'built_at' => '2026-09-02T00:00:00+00:00',
            'model' => 'text-embedding-3-large', 'dims' => 3, 'count' => 2,
            'normalised' => true,
            'chunks' => [
                ['id' => 'hyptest-003', 'doc_id' => 'hyptest', 'doc_title' => 'Lecture Notes',
                    'heading_chain' => ['2 p-Values'], 'page_start' => 2, 'page_end' => 2,
                    'n_tokens' => 165, 'text' => '## 2 p-Values ... p = P(T >= c | H0)'],
                ['id' => 'hyptest-011', 'doc_id' => 'hyptest', 'doc_title' => 'Lecture Notes',
                    'heading_chain' => ['5 Type I and Type II Errors', 'Statistical Power'],
                    'page_start' => 4, 'page_end' => 4, 'n_tokens' => 77, 'text' => '## Power = 1 - beta'],
            ],
        ]));
    }

    private function seedStudent(int $tokens = 10000, ?string $arm = 'rag'): Student
    {
        $s = new Student;
        $s->uid = 'u:test01';
        $s->firstname = 'Test';
        $s->lastname = 'Student';
        $s->token_limit = $tokens;
        $s->token_left = $tokens;
        $s->activated = true;
        $s->arm = $arm;
        $s->save();

        return $s;
    }

    private function fakeAzure(array $answer, int $promptTokens = 900, int $completionTokens = 300): void
    {
        Http::fake([
            '*/embeddings*' => Http::response(['data' => [['embedding' => [1, 0, 0], 'index' => 0]]]),
            '*/chat/completions*' => Http::response([
                'choices' => [['message' => ['content' => json_encode($answer)]]],
                'usage' => [
                    'prompt_tokens' => $promptTokens,
                    'completion_tokens' => $completionTokens,
                    'total_tokens' => $promptTokens + $completionTokens,
                ],
            ]),
        ]);
    }

    private function ask(string $question): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/messages', [
            'messages' => [['role' => 'user', 'content' => $question]],
            'started' => 1756800000000,
        ]);
    }

    public function test_grounded_answer_returns_one_answer_with_sources(): void
    {
        $this->seedStudent();
        $this->fakeAzure([
            'answer' => 'Your notes define the p-value as P(T >= c | H0); it measures how '
                .'surprising the data would be under H0.',
            'citations' => ['hyptest-003'],
        ]);

        $this->ask('what is a p-value?')
            ->assertOk()
            ->assertJsonPath('grounded', true)
            ->assertJsonPath('sources.0.id', 'hyptest-003')
            ->assertJsonPath('sources.0.page_start', 2)
            ->assertJsonMissingPath('from_materials')
            ->assertJsonMissingPath('materials_note')
            ->assertJsonStructure(['content', 'sources', 'grounded', 'token_left', 'costs']);

        // which corpus answered must be recorded at write time - it cannot be
        // reconstructed once the index is rebuilt
        $row = History::first();
        $this->assertSame(1, (int) $row->grounded);
        $this->assertStringStartsWith('hyptest@', $row->kb_version);
        $this->assertStringContainsString('hyptest-003', $row->kb_chunks);
    }

    public function test_history_sent_records_the_students_words_not_the_injected_context(): void
    {
        $this->seedStudent();
        $this->fakeAzure([
            'answer' => 'one coherent answer',
            'citations' => ['hyptest-003'],
        ]);

        $question = 'what is a p-value?';
        $this->ask($question)->assertOk();

        $row = History::first();
        $this->assertSame($question, $row->sent,
            'injected passages must never leak into the persisted student message');
        $this->assertStringNotContainsString('Course materials', $row->sent);
        $this->assertStringContainsString('one coherent answer', $row->received);
    }

    public function test_the_prompt_sent_to_azure_has_the_cacheable_ordering(): void
    {
        $this->seedStudent();
        $this->fakeAzure(['answer' => 'a', 'citations' => ['hyptest-003']]);

        $this->ask('what is a p-value?')->assertOk();

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'chat/completions')) {
                return true;
            }
            $messages = $request['messages'];

            // system prompt first, student's turn last, passages immediately before it
            $this->assertSame('system', $messages[0]['role']);
            $this->assertSame('user', $messages[count($messages) - 1]['role']);
            $this->assertSame('what is a p-value?', $messages[count($messages) - 1]['content']);
            $this->assertStringContainsString('hyptest-003', $messages[count($messages) - 2]['content']);
            $this->assertArrayHasKey('response_format', $request->data());

            return true;
        });
    }

    public function test_rag_disabled_skips_retrieval_entirely(): void
    {
        config(['tutor.rag_enabled' => false]);
        $this->seedStudent();
        $this->fakeAzure(['answer' => 'general only', 'citations' => []]);

        $this->ask('what is a p-value?')
            ->assertOk()
            ->assertJsonPath('grounded', false)
            ->assertJsonPath('sources', []);

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'embeddings'));
    }

    public function test_out_of_corpus_question_is_answered_without_sources_or_a_note(): void
    {
        $this->seedStudent();
        Http::fake([
            // orthogonal to every chunk, so nothing clears the floor
            '*/embeddings*' => Http::response(['data' => [['embedding' => [0, 0, 1], 'index' => 0]]]),
            '*/chat/completions*' => Http::response([
                'choices' => [['message' => ['content' => json_encode([
                    'answer' => 'ANOVA partitions variance into between and within components.',
                    'citations' => [],
                ])]]],
                'usage' => ['prompt_tokens' => 400, 'completion_tokens' => 200, 'total_tokens' => 600],
            ]),
        ]);

        $response = $this->ask('how do I compute an F-ratio in ANOVA?')->assertOk();

        $response->assertJsonPath('grounded', false)->assertJsonPath('sources', []);

        // the old two-layer shape announced "not covered by the course materials"
        // on every ungrounded turn, which marked the control arm on turn one
        $this->assertStringContainsString('ANOVA partitions variance', $response->json('content'));
        $this->assertStringNotContainsStringIgnoringCase('course materials', $response->json('content'));
    }

    public function test_tokens_are_still_billed_to_the_student(): void
    {
        $this->seedStudent(10000);
        $this->fakeAzure(['answer' => 'a', 'citations' => ['hyptest-003']], 900, 300);

        $this->ask('what is a p-value?')
            ->assertOk()
            ->assertJsonPath('token_left', 10000 - 1200)
            ->assertJsonPath('costs', 1200);

        $this->assertSame(8800, (int) Student::first()->token_left);
    }

    public function test_a_retrieval_outage_still_answers(): void
    {
        $this->seedStudent();
        Http::fake([
            '*/embeddings*' => Http::response('boom', 503),
            '*/chat/completions*' => Http::response([
                'choices' => [['message' => ['content' => json_encode([
                    'answer' => 'still helpful', 'citations' => [],
                ])]]],
                'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 50, 'total_tokens' => 150],
            ]),
        ]);

        $this->ask('what is a p-value?')
            ->assertOk()
            ->assertJsonPath('grounded', false)
            ->assertJsonPath('content', 'still helpful');
    }

    public function test_the_no_rag_arm_skips_retrieval_even_while_rag_is_enabled(): void
    {
        // this is the trial itself: RAG_ENABLED stays true study-wide, and the
        // student's arm is what decides whether this turn is grounded
        $this->seedStudent(10000, 'no_rag');
        $this->fakeAzure(['answer' => 'ungrounded answer', 'citations' => ['hyptest-003']]);

        $this->ask('what is a p-value?')
            ->assertOk()
            ->assertJsonPath('grounded', false)
            ->assertJsonPath('sources', []);

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'embeddings'));

        $row = History::first();
        $this->assertNull($row->kb_version);
        $this->assertNull($row->kb_chunks);
    }

    public function test_the_control_arm_gets_the_base_prompt_without_the_materials_block(): void
    {
        $this->seedStudent(10000, 'no_rag');
        $this->fakeAzure(['answer' => 'a', 'citations' => []]);

        $this->ask('what is a p-value?')->assertOk();

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'chat/completions')) {
                return true;
            }
            $this->assertSame(
                config('tutor.system_prompt_base'),
                $request['messages'][0]['content']
            );

            return true;
        });
    }

    public function test_the_arm_is_stamped_on_the_history_row(): void
    {
        // stamped, never joined from students.arm - a join would label this
        // student's pre-study messages with the arm they were later allocated to
        $this->seedStudent(10000, 'rag');
        $this->fakeAzure(['answer' => 'a', 'citations' => ['hyptest-003']]);

        $this->ask('what is a p-value?')->assertOk();

        $this->assertSame('rag', History::first()->arm);
    }

    public function test_an_unallocated_student_is_never_grounded(): void
    {
        // arm = NULL is every student who used StatsBot before the trial opened
        $this->seedStudent(10000, null);
        $this->fakeAzure(['answer' => 'a', 'citations' => []]);

        $this->ask('what is a p-value?')->assertOk()->assertJsonPath('grounded', false);

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'embeddings'));
        $this->assertNull(History::first()->arm);
    }

    public function test_students_out_of_tokens_are_still_refused(): void
    {
        $this->seedStudent(0);
        $this->fakeAzure(['answer' => 'a', 'citations' => []]);

        $this->ask('what is a p-value?')->assertStatus(403);
        Http::assertNothingSent();
    }
}
