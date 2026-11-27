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
            'tutor.max_citations' => 3,
            'tutor.embed.endpoint' => 'https://example.invalid',
            'tutor.embed.deployment' => 'test-embed',
            'tutor.embed.model' => 'text-embedding-3-large',
            'tutor.embed.dims' => 3,
            'tutor.embed.timeout' => 5,
            'study.phase' => 'test',
            'study.arm_override' => false,
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

    private function seedStudent(int $tokens = 10000, ?string $arm = 'rag_salient'): Student
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

    private function ask(string $question, array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/messages', array_merge([
            'messages' => [['role' => 'user', 'content' => $question]],
            'started' => 1756800000000,
        ], $extra));
    }

    /** Raw bodies of every chat/completions request sent so far. */
    private function chatBodies(): array
    {
        return collect(Http::recorded())
            ->filter(fn ($pair) => str_contains($pair[0]->url(), 'chat/completions'))
            ->map(fn ($pair) => $pair[0]->body())
            ->values()
            ->all();
    }

    public function test_grounded_answer_returns_one_answer_with_sources(): void
    {
        $this->seedStudent();
        $this->fakeAzure([
            'answer' => 'The p-value is P(T >= c | H0); it measures how '
                .'surprising the data would be under H0.',
            'citations' => ['hyptest-003'],
        ]);

        $this->ask('what is a p-value?')
            ->assertOk()
            ->assertJsonPath('sources.0.id', 'hyptest-003')
            ->assertJsonPath('sources.0.label', '2 p-Values, p. 2')
            ->assertJsonMissingPath('sources.0.score')
            ->assertJsonMissingPath('grounded')
            ->assertJsonMissingPath('costs')
            ->assertJsonStructure(['content', 'sources', 'token_left']);

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
        $this->assertSame('one coherent answer', $row->received);
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

        // salient still shows its note - nothing was cited, which is true
        $this->ask('what is a p-value?')
            ->assertOk()
            ->assertJsonPath('sources', []);

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'embeddings'));
        $this->assertNull(History::first()->cited);
    }

    public function test_out_of_corpus_question_is_answered_with_no_chips_and_no_prose_note(): void
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

        // [] = the salient arm's UI note; the PROSE carries no such note - the
        // old two-layer shape announced "not covered by the course materials"
        // on every ungrounded turn, which marked the control arm on turn one
        $response->assertJsonPath('sources', []);
        $this->assertStringContainsString('ANOVA partitions variance', $response->json('content'));
        $this->assertStringNotContainsStringIgnoringCase('course materials', $response->json('content'));
    }

    public function test_tokens_are_still_billed_to_the_student(): void
    {
        $this->seedStudent(10000);
        $this->fakeAzure(['answer' => 'a', 'citations' => ['hyptest-003']], 900, 300);

        $this->ask('what is a p-value?')
            ->assertOk()
            ->assertJsonPath('token_left', 10000 - 1200);

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
            ->assertJsonPath('content', 'still helpful')
            ->assertJsonPath('sources', []);
    }

    public function test_the_no_rag_arm_skips_retrieval_even_while_rag_is_enabled(): void
    {
        // this is the trial itself: RAG_ENABLED stays true study-wide, and the
        // student's arm is what decides whether this turn is grounded
        $this->seedStudent(10000, 'no_rag');
        $this->fakeAzure(['answer' => 'ungrounded answer', 'citations' => ['hyptest-003']]);

        $this->ask('what is a p-value?')
            ->assertOk()
            ->assertJsonMissingPath('sources');

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'embeddings'));

        $row = History::first();
        $this->assertNull($row->kb_version);
        $this->assertNull($row->kb_chunks);
        $this->assertNull($row->cited);
        $this->assertNull($row->sources_shown);
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
        $this->seedStudent(10000, 'rag_nonsalient');
        $this->fakeAzure(['answer' => 'a', 'citations' => ['hyptest-003']]);

        $this->ask('what is a p-value?')->assertOk();

        $this->assertSame('rag_nonsalient', History::first()->arm);
    }

    public function test_an_unallocated_student_is_never_grounded(): void
    {
        // arm = NULL is every student who used StatsBot before the trial opened
        $this->seedStudent(10000, null);
        $this->fakeAzure(['answer' => 'a', 'citations' => []]);

        $this->ask('what is a p-value?')->assertOk()->assertJsonMissingPath('sources');

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'embeddings'));
        $this->assertNull(History::first()->arm);
    }

    public function test_the_two_arm_pilots_old_rag_value_falls_back_to_control(): void
    {
        // a student still holding 'rag' after the switch to three arms must not
        // be half-treated; control behaviour, and a warning in the log
        $this->seedStudent(10000, 'rag');
        $this->fakeAzure(['answer' => 'a', 'citations' => ['hyptest-003']]);

        $this->ask('what is a p-value?')->assertOk()->assertJsonMissingPath('sources');

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'embeddings'));
    }

    public function test_students_out_of_tokens_are_still_refused(): void
    {
        $this->seedStudent(0);
        $this->fakeAzure(['answer' => 'a', 'citations' => []]);

        $this->ask('what is a p-value?')->assertStatus(403);
        Http::assertNothingSent();
    }

    /*
    |--------------------------------------------------------------------------
    | Three arms: the RAG arms are one tutor, displayed two ways
    |--------------------------------------------------------------------------
    */

    public function test_both_rag_arms_send_byte_identical_requests_to_azure(): void
    {
        // THE invariant of the design. The model samples, so the answer TEXT of
        // two arms is only equal in distribution - but what they ask the model
        // can be, and must be, identical to the byte.
        $student = $this->seedStudent(10000, 'rag_nonsalient');
        $this->fakeAzure(['answer' => 'a', 'citations' => ['hyptest-003']]);

        $this->ask('what is a p-value?')->assertOk();
        $student->forceFill(['arm' => 'rag_salient'])->save();
        $this->ask('what is a p-value?')->assertOk();
        $student->forceFill(['arm' => 'no_rag'])->save();
        $this->ask('what is a p-value?')->assertOk();

        [$nonsalient, $salient, $control] = $this->chatBodies();

        $this->assertSame($nonsalient, $salient);
        // ...and the comparison can fail: the control arm's request differs
        $this->assertNotSame($nonsalient, $control);
    }

    public function test_non_salient_and_no_rag_responses_are_indistinguishable_in_shape(): void
    {
        $student = $this->seedStudent(10000, 'rag_nonsalient');
        $this->fakeAzure(['answer' => 'a', 'citations' => ['hyptest-003']]);

        $nonsalient = $this->ask('what is a p-value?')->assertOk()->json();
        $student->forceFill(['arm' => 'no_rag'])->save();
        $control = $this->ask('what is a p-value?')->assertOk()->json();

        $this->assertSame(['content', 'token_left'], array_keys($nonsalient));
        $this->assertSame(array_keys($control), array_keys($nonsalient));
    }

    public function test_cited_is_recorded_for_both_rag_arms_but_shown_only_for_salient(): void
    {
        $student = $this->seedStudent(10000, 'rag_nonsalient');
        $this->fakeAzure(['answer' => 'a', 'citations' => ['hyptest-003']]);

        $this->ask('what is a p-value?')->assertOk()->assertJsonMissingPath('sources');
        $student->forceFill(['arm' => 'rag_salient'])->save();
        $this->ask('what is a p-value?')->assertOk()->assertJsonPath('sources.0.id', 'hyptest-003');

        [$nonsalient, $salient] = History::orderBy('id')->get()->all();

        // grounding and misattribution stay measurable in the arm that hides them
        $this->assertSame('hyptest-003', json_decode($nonsalient->cited, true)[0]['id']);
        $this->assertNull($nonsalient->sources_shown);

        $this->assertSame('hyptest-003', json_decode($salient->cited, true)[0]['id']);
        $this->assertSame(
            [['id' => 'hyptest-003', 'label' => '2 p-Values, p. 2']],
            json_decode($salient->sources_shown, true)
        );
    }

    public function test_salient_with_retrieval_but_no_citation_shows_the_note(): void
    {
        // something was retrieved, the model drew on none of it - the note
        // appears whenever no chip does, not only when retrieval came up empty
        $this->seedStudent(10000, 'rag_salient');
        $this->fakeAzure(['answer' => 'a', 'citations' => []]);

        $this->ask('what is a p-value?')->assertOk()->assertJsonPath('sources', []);

        $row = History::first();
        $this->assertNotNull($row->kb_chunks, 'retrieval did return passages');
        $this->assertSame('[]', $row->cited);
        $this->assertSame('[]', $row->sources_shown);
    }

    public function test_the_answer_text_is_the_same_in_both_rag_arms(): void
    {
        // no "_Source: ..._" line in the prose: it would show the citation to the
        // non-salient arm
        $student = $this->seedStudent(10000, 'rag_salient');
        $this->fakeAzure(['answer' => 'The p-value is P(T >= c | H0).', 'citations' => ['hyptest-003']]);

        $salient = $this->ask('what is a p-value?')->assertOk()->json('content');
        $student->forceFill(['arm' => 'rag_nonsalient'])->save();
        $nonsalient = $this->ask('what is a p-value?')->assertOk()->json('content');

        $this->assertSame('The p-value is P(T >= c | H0).', $salient);
        $this->assertSame($salient, $nonsalient);
        $this->assertSame(
            History::orderBy('id')->first()->received,
            History::orderBy('id', 'desc')->first()->received
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Blinding: the browser never learns the arm
    |--------------------------------------------------------------------------
    */

    public function test_the_thread_endpoint_returns_only_what_the_chat_renders(): void
    {
        $student = $this->seedStudent(10000, 'rag_nonsalient');
        $this->fakeAzure(['answer' => 'a', 'citations' => ['hyptest-003']]);
        $this->ask('what is a p-value?')->assertOk();
        $student->forceFill(['arm' => 'rag_salient'])->save();
        $this->ask('and power?')->assertOk();

        $thread = $this->getJson('/api/history/1756800000000')->assertOk()->json();

        // non-salient row: no sources key at all, like a no-RAG row
        $this->assertSame(['id', 'sent', 'received', 'started', 'created_at'], array_keys($thread[0]));
        // salient row: the chips it showed live, replayed from sources_shown
        $this->assertSame([['id' => 'hyptest-003', 'label' => '2 p-Values, p. 2']], $thread[1]['sources']);

        foreach ($thread as $row) {
            foreach (['arm', 'kb_chunks', 'kb_version', 'cited', 'grounded', 'total_tokens'] as $leak) {
                $this->assertArrayNotHasKey($leak, $row);
            }
        }
    }

    public function test_the_student_endpoint_never_reveals_the_arm(): void
    {
        $this->seedStudent(10000, 'rag_salient');

        $this->getJson('/api/student')
            ->assertOk()
            ->assertJsonPath('uid', 'u:test01')
            ->assertJsonMissingPath('arm')
            ->assertJsonMissingPath('allocated_at');
    }

    /*
    |--------------------------------------------------------------------------
    | Test URLs: a pinned arm, on the test stack only
    |--------------------------------------------------------------------------
    */

    public function test_a_pinned_arm_is_honoured_on_the_test_stack(): void
    {
        config(['study.arm_override' => true]);
        $this->seedStudent(10000, 'no_rag');
        $this->fakeAzure(['answer' => 'a', 'citations' => ['hyptest-003']]);

        $this->ask('what is a p-value?', ['test_arm' => 'rag_salient'])
            ->assertOk()
            ->assertJsonPath('sources.0.id', 'hyptest-003');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'embeddings'));
        // filed under the arm that actually answered, not the allocated one
        $this->assertSame('rag_salient', History::first()->arm);
        $this->assertSame('no_rag', Student::first()->arm, 'pinning never reallocates');
    }

    public function test_a_pinned_arm_is_refused_while_the_override_is_off(): void
    {
        $this->seedStudent(10000, 'no_rag');
        $this->fakeAzure(['answer' => 'a', 'citations' => []]);

        $this->ask('what is a p-value?', ['test_arm' => 'rag_salient'])->assertStatus(403);

        Http::assertNothingSent();
        $this->assertSame(0, History::count());
    }

    public function test_a_pinned_arm_is_refused_in_the_study_phase_even_with_the_override_on(): void
    {
        // a misconfigured .env must not let a student switch arms mid-study
        config(['study.arm_override' => true, 'study.phase' => 'study']);
        $this->seedStudent(10000, 'no_rag');
        $this->fakeAzure(['answer' => 'a', 'citations' => []]);

        $this->ask('what is a p-value?', ['test_arm' => 'rag_salient'])->assertStatus(403);

        Http::assertNothingSent();
    }

    public function test_a_pinned_arm_needs_exactly_the_test_phase(): void
    {
        // the gate is "phase IS test", not "phase is not study": a stack in 'off'
        // or with a misspelt phase must refuse too
        $this->seedStudent(10000, 'no_rag');
        $this->fakeAzure(['answer' => 'a', 'citations' => []]);

        foreach (['off', 'Study', 'TEST', ''] as $phase) {
            config(['study.arm_override' => true, 'study.phase' => $phase]);

            $this->ask('what is a p-value?', ['test_arm' => 'rag_salient'])
                ->assertStatus(403, "phase '$phase' must refuse a pinned arm");
        }

        Http::assertNothingSent();
    }

    public function test_an_unknown_pinned_arm_is_rejected(): void
    {
        config(['study.arm_override' => true]);
        $this->seedStudent(10000, 'no_rag');
        $this->fakeAzure(['answer' => 'a', 'citations' => []]);

        $this->ask('what is a p-value?', ['test_arm' => 'rag'])->assertStatus(422);

        Http::assertNothingSent();
    }

    /*
    |--------------------------------------------------------------------------
    | Follow-ups: two searches, one embeddings call
    |--------------------------------------------------------------------------
    */

    /** Chat answer as fakeAzure, but one embedding per input, picked by its text. */
    private function fakeAzureByInput(array $vectors, array $answer): void
    {
        Http::fake([
            '*/embeddings*' => function ($request) use ($vectors) {
                $data = [];
                foreach ((array) $request['input'] as $i => $text) {
                    $data[] = ['embedding' => $vectors[$text] ?? [0, 0, 1], 'index' => $i];
                }

                return Http::response(['data' => $data]);
            },
            '*/chat/completions*' => Http::response([
                'choices' => [['message' => ['content' => json_encode($answer)]]],
                'usage' => ['prompt_tokens' => 900, 'completion_tokens' => 300, 'total_tokens' => 1200],
            ]),
        ]);
    }

    private function followUp(): array
    {
        return [
            ['role' => 'user', 'content' => 'what is statistical power?'],
            ['role' => 'assistant', 'content' => 'Power is the probability of rejecting a false H0.'],
            ['role' => 'user', 'content' => 'und warum?'],
        ];
    }

    public function test_a_follow_up_is_searched_with_and_without_the_previous_question(): void
    {
        $this->seedStudent(10000, 'rag_salient');
        // "und warum?" alone matches nothing; with the previous question it finds the power chunk
        $this->fakeAzureByInput(["what is statistical power?\nund warum?" => [0, 1, 0]],
            ['answer' => 'a', 'citations' => ['hyptest-011']]);

        $this->postJson('/api/messages', ['messages' => $this->followUp(), 'started' => 1756800000000])
            ->assertOk()
            ->assertJsonPath('sources.0.id', 'hyptest-011');

        $embedCalls = collect(Http::recorded())->filter(fn ($p) => str_contains($p[0]->url(), 'embeddings'));
        $this->assertCount(1, $embedCalls, 'both searches share one embeddings request');
        $this->assertSame(['und warum?', "what is statistical power?\nund warum?"], $embedCalls->first()[0]['input']);

        $chunks = json_decode(History::first()->kb_chunks, true);
        // assertEquals: json_encode writes the score 1.0 as 1
        $this->assertEquals([['id' => 'hyptest-011', 'score' => 1.0, 'q' => 1]], $chunks,
            'the context search found it, and history says so');
        $this->assertSame('und warum?', History::first()->sent, 'history.sent stays the student\'s own words');
    }

    public function test_both_rag_arms_still_send_identical_requests_on_a_follow_up(): void
    {
        $student = $this->seedStudent(10000, 'rag_salient');
        $this->fakeAzureByInput(["what is statistical power?\nund warum?" => [0, 1, 0]],
            ['answer' => 'a', 'citations' => ['hyptest-011']]);

        $this->postJson('/api/messages', ['messages' => $this->followUp(), 'started' => 1756800000000])->assertOk();
        $student->forceFill(['arm' => 'rag_nonsalient'])->save();
        $this->postJson('/api/messages', ['messages' => $this->followUp(), 'started' => 1756800000000])->assertOk();

        $bodies = collect(Http::recorded())->map(fn ($p) => $p[0]->url().' '.$p[0]->body())->values();
        $this->assertCount(4, $bodies);
        $this->assertSame($bodies[0], $bodies[2], 'embeddings requests are identical across arms');
        $this->assertSame($bodies[1], $bodies[3], 'chat requests are identical across arms');
    }

    public function test_salient_chips_carry_the_label_parts_when_the_index_has_them(): void
    {
        $meta = json_decode(file_get_contents($this->indexBase.'.json'), true);
        $meta['chunks'][0] += [
            'citation' => 'Statistik I · Vorlesung 12: Statistischer Hypothesentest · Folie 21',
            'cite_course' => 'Statistik I', 'cite_doc' => 'Vorlesung 12',
            'cite_topic' => 'Statistischer Hypothesentest', 'cite_pos' => 'Folie 21',
        ];
        file_put_contents($this->indexBase.'.json', json_encode($meta));
        $this->seedStudent(10000, 'rag_salient');
        $this->fakeAzure(['answer' => 'a', 'citations' => ['hyptest-003']]);

        $chip = $this->ask('what is a p-value?')->assertOk()->json('sources.0');

        $this->assertSame('Statistik I · Vorlesung 12: Statistischer Hypothesentest · Folie 21', $chip['label']);
        $this->assertSame(['course' => 'Statistik I', 'doc' => 'Vorlesung 12',
            'topic' => 'Statistischer Hypothesentest', 'pos' => 'Folie 21'], $chip['cite']);
        $this->assertSame($chip, json_decode(History::first()->sources_shown, true)[0],
            'a reloaded thread replays exactly the chip that was shown');
    }
}
