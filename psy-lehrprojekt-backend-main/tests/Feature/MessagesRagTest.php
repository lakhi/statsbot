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

    private function seedStudent(int $tokens = 10000): Student
    {
        $s = new Student;
        $s->uid = 'u:test01';
        $s->firstname = 'Test';
        $s->lastname = 'Student';
        $s->matnr = '01234567';
        $s->lv = 'PSY-STATS';
        $s->token_limit = $tokens;
        $s->token_left = $tokens;
        $s->activated = true;
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

    public function test_grounded_answer_returns_both_layers_with_sources(): void
    {
        $this->seedStudent();
        $this->fakeAzure([
            'from_materials' => 'Your notes define the p-value as P(T >= c | H0).',
            'from_general' => 'It measures how surprising the data would be under H0.',
            'citations' => ['hyptest-003'],
        ]);

        $this->ask('what is a p-value?')
            ->assertOk()
            ->assertJsonPath('grounded', true)
            ->assertJsonPath('from_materials', 'Your notes define the p-value as P(T >= c | H0).')
            ->assertJsonPath('sources.0.id', 'hyptest-003')
            ->assertJsonPath('sources.0.page_start', 2)
            ->assertJsonStructure(['content', 'from_materials', 'from_general', 'sources', 'token_left', 'costs']);
    }

    public function test_history_sent_records_the_students_words_not_the_injected_context(): void
    {
        $this->seedStudent();
        $this->fakeAzure([
            'from_materials' => 'materials layer',
            'from_general' => 'general layer',
            'citations' => ['hyptest-003'],
        ]);

        $question = 'what is a p-value?';
        $this->ask($question)->assertOk();

        $row = History::first();
        $this->assertSame($question, $row->sent,
            'injected passages must never leak into the persisted student message');
        $this->assertStringNotContainsString('Course materials', $row->sent);
        $this->assertStringContainsString('materials layer', $row->received);
    }

    public function test_the_prompt_sent_to_azure_has_the_cacheable_ordering(): void
    {
        $this->seedStudent();
        $this->fakeAzure(['from_materials' => 'm', 'from_general' => 'g', 'citations' => ['hyptest-003']]);

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
        $this->fakeAzure(['from_materials' => null, 'from_general' => 'general only', 'citations' => []]);

        $this->ask('what is a p-value?')
            ->assertOk()
            ->assertJsonPath('grounded', false)
            ->assertJsonPath('from_materials', null);

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'embeddings'));
    }

    public function test_out_of_corpus_question_gets_the_not_covered_note(): void
    {
        $this->seedStudent();
        Http::fake([
            // orthogonal to every chunk, so nothing clears the floor
            '*/embeddings*' => Http::response(['data' => [['embedding' => [0, 0, 1], 'index' => 0]]]),
            '*/chat/completions*' => Http::response([
                'choices' => [['message' => ['content' => json_encode([
                    'from_materials' => null,
                    'from_general' => 'ANOVA partitions variance into between and within components.',
                    'citations' => [],
                ])]]],
                'usage' => ['prompt_tokens' => 400, 'completion_tokens' => 200, 'total_tokens' => 600],
            ]),
        ]);

        $response = $this->ask('how do I compute an F-ratio in ANOVA?')->assertOk();

        $response->assertJsonPath('grounded', false);
        $this->assertStringContainsString(config('tutor.no_material_note'), $response->json('content'));
        $this->assertStringContainsString('ANOVA partitions variance', $response->json('from_general'));
    }

    public function test_tokens_are_still_billed_to_the_student(): void
    {
        $this->seedStudent(10000);
        $this->fakeAzure(['from_materials' => 'm', 'from_general' => 'g', 'citations' => ['hyptest-003']], 900, 300);

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
                    'from_materials' => null, 'from_general' => 'still helpful', 'citations' => [],
                ])]]],
                'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 50, 'total_tokens' => 150],
            ]),
        ]);

        $this->ask('what is a p-value?')
            ->assertOk()
            ->assertJsonPath('grounded', false)
            ->assertJsonPath('from_general', 'still helpful');
    }

    public function test_students_out_of_tokens_are_still_refused(): void
    {
        $this->seedStudent(0);
        $this->fakeAzure(['from_materials' => null, 'from_general' => 'g', 'citations' => []]);

        $this->ask('what is a p-value?')->assertStatus(403);
        Http::assertNothingSent();
    }
}
