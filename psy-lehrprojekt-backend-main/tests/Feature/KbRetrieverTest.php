<?php

namespace Tests\Feature;

use App\Services\KbRetriever;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class KbRetrieverTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        $this->base = sys_get_temp_dir().'/kbtest-'.uniqid();
        config([
            'tutor.index_path' => $this->base,
            'tutor.top_k' => 3,
            'tutor.min_score' => 0.35,
            'tutor.embed.endpoint' => 'https://example.invalid',
            'tutor.embed.deployment' => 'test-embed',
            'tutor.embed.api_version' => '2024-10-21',
            'tutor.embed.model' => 'text-embedding-3-large',
            'tutor.embed.dims' => 3,
            'tutor.embed.timeout' => 5,
        ]);
    }

    protected function tearDown(): void
    {
        @unlink($this->base.'.f32');
        @unlink($this->base.'.json');
        parent::tearDown();
    }

    /**
     * Three orthogonal unit vectors, so a query aligned with one scores exactly
     * 1.0 against it and 0.0 against the others - the arithmetic is checkable by
     * hand rather than by eyeballing floats.
     *
     * @param  array<int,array<int,float>>  $vectors
     */
    private function writeIndex(array $vectors, array $overrides = []): void
    {
        $blob = '';
        foreach ($vectors as $v) {
            $blob .= pack('g*', ...$v);
        }
        file_put_contents($this->base.'.f32', $blob);

        $meta = array_merge([
            'index_version' => 1,
            'doc_id' => 'testdoc',
            'built_at' => '2026-09-02T00:00:00+00:00',
            'model' => 'text-embedding-3-large',
            'dims' => 3,
            'count' => count($vectors),
            'normalised' => true,
            'chunks' => array_map(fn ($i) => [
                'id' => "testdoc-00{$i}",
                'doc_id' => 'testdoc',
                'doc_title' => 'Test Doc',
                'heading_chain' => ["Section {$i}"],
                'page_start' => $i + 1,
                'page_end' => $i + 1,
                'n_tokens' => 10,
                'text' => "body of section {$i}",
            ], array_keys($vectors)),
        ], $overrides);

        file_put_contents($this->base.'.json', json_encode($meta));
    }

    private function fakeQueryVector(array $v): void
    {
        Http::fake([
            '*/embeddings*' => Http::response(['data' => [['embedding' => $v, 'index' => 0]]]),
        ]);
    }

    public function test_returns_the_aligned_chunk_first(): void
    {
        $this->writeIndex([[1, 0, 0], [0, 1, 0], [0, 0, 1]]);
        $this->fakeQueryVector([0, 1, 0]);

        $hits = (new KbRetriever)->search('anything');

        $this->assertNotEmpty($hits);
        $this->assertSame('testdoc-001', $hits[0]['id']);
        $this->assertEqualsWithDelta(1.0, $hits[0]['score'], 0.0001);
        $this->assertSame('Section 1', $hits[0]['heading']);
        $this->assertSame(2, $hits[0]['page_start']);
    }

    public function test_the_label_is_the_chunks_citation_when_the_pipeline_wrote_one(): void
    {
        $this->writeIndex([[1, 0, 0]]);
        $meta = json_decode(file_get_contents($this->base.'.json'), true);
        $meta['chunks'][0]['citation'] = 'Vorlesung 14: t-Test I, Folie 12';
        file_put_contents($this->base.'.json', json_encode($meta));
        $this->fakeQueryVector([1, 0, 0]);

        $hits = (new KbRetriever)->search('anything');

        $this->assertSame('Vorlesung 14: t-Test I, Folie 12', $hits[0]['label']);
    }

    public function test_an_index_without_citations_falls_back_to_heading_and_page(): void
    {
        // the hyptest pilot index predates the citation field
        $this->writeIndex([[1, 0, 0]]);
        $this->fakeQueryVector([1, 0, 0]);

        $hits = (new KbRetriever)->search('anything');

        $this->assertSame('Section 0, p. 1', $hits[0]['label']);
    }

    public function test_chunks_below_the_similarity_floor_are_dropped(): void
    {
        // Only the second chunk clears 0.35; the orthogonal ones score 0.
        $this->writeIndex([[1, 0, 0], [0, 1, 0], [0, 0, 1]]);
        $this->fakeQueryVector([0, 1, 0]);

        $hits = (new KbRetriever)->search('anything');

        $this->assertCount(1, $hits, 'sub-threshold chunks must not reach the prompt');
    }

    public function test_everything_below_the_floor_yields_no_materials_layer(): void
    {
        // Query sits between axes: 0.577 against each... so push it further away.
        $this->writeIndex([[1, 0, 0], [0, 1, 0]]);
        $this->fakeQueryVector([0.3, 0.3, 0.9055]);

        $hits = (new KbRetriever)->search('a question the corpus cannot answer');

        $this->assertSame([], $hits);
    }

    public function test_top_k_is_respected(): void
    {
        config(['tutor.top_k' => 2, 'tutor.min_score' => 0.0]);
        $this->writeIndex([[1, 0, 0], [0, 1, 0], [0, 0, 1]]);
        $this->fakeQueryVector([0.6, 0.8, 0.0]);

        $hits = (new KbRetriever)->search('anything');

        $this->assertCount(2, $hits);
        $this->assertSame('testdoc-001', $hits[0]['id']); // 0.8
        $this->assertSame('testdoc-000', $hits[1]['id']); // 0.6
    }

    public function test_a_model_mismatch_degrades_instead_of_citing_nonsense(): void
    {
        // Vectors from a different model live in a different space: every score
        // would be meaningless while nothing errors. Retrieval must refuse.
        $this->writeIndex([[1, 0, 0]], ['model' => 'text-embedding-3-small']);
        $this->fakeQueryVector([1, 0, 0]);

        $this->assertSame([], (new KbRetriever)->search('anything'));
    }

    public function test_a_truncated_index_degrades_instead_of_reading_garbage(): void
    {
        $this->writeIndex([[1, 0, 0], [0, 1, 0]]);
        file_put_contents($this->base.'.f32', substr(file_get_contents($this->base.'.f32'), 0, 8));
        $this->fakeQueryVector([1, 0, 0]);

        $this->assertSame([], (new KbRetriever)->search('anything'));
    }

    public function test_a_missing_index_degrades_instead_of_erroring(): void
    {
        $this->fakeQueryVector([1, 0, 0]);

        $this->assertSame([], (new KbRetriever)->search('anything'),
            'a broken index must degrade the tutor, never take it offline');
    }

    public function test_an_embeddings_outage_degrades_instead_of_erroring(): void
    {
        $this->writeIndex([[1, 0, 0]]);
        Http::fake(['*/embeddings*' => Http::response('upstream boom', 503)]);

        $this->assertSame([], (new KbRetriever)->search('anything'));
    }

    public function test_empty_query_makes_no_http_call(): void
    {
        $this->writeIndex([[1, 0, 0]]);
        Http::fake();

        $this->assertSame([], (new KbRetriever)->search('   '));
        Http::assertNothingSent();
    }

    /** One embedding per input, chosen by the input text, so tests can steer each search. */
    private function fakeVectorsByInput(array $byText): void
    {
        Http::fake([
            '*/embeddings*' => function ($request) use ($byText) {
                $data = [];
                foreach ((array) $request['input'] as $i => $text) {
                    $data[] = ['embedding' => $byText[$text], 'index' => $i];
                }

                // reversed on purpose: the API's `index`, not array order, is authoritative
                return Http::response(['data' => array_reverse($data)]);
            },
        ]);
    }

    public function test_queries_for_a_first_message_is_the_message_alone(): void
    {
        $this->assertSame(['what is a p-value?'], KbRetriever::queriesFor([
            ['role' => 'user', 'content' => '  what is a p-value? '],
        ]));
    }

    public function test_queries_for_a_follow_up_add_the_previous_student_message_only(): void
    {
        $queries = KbRetriever::queriesFor([
            ['role' => 'user', 'content' => 'what is a t-test?'],
            ['role' => 'assistant', 'content' => 'A long tutor answer that must not reach the search.'],
            ['role' => 'user', 'content' => 'und warum?'],
        ]);

        $this->assertSame(['und warum?', "what is a t-test?\nund warum?"], $queries);
    }

    public function test_two_queries_go_out_in_one_embeddings_request(): void
    {
        $this->writeIndex([[1, 0, 0], [0, 1, 0]]);
        $this->fakeVectorsByInput(['a' => [1, 0, 0], 'b' => [0, 1, 0]]);

        (new KbRetriever)->search(['a', 'b']);

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request['input'] === ['a', 'b']);
    }

    public function test_identical_queries_are_sent_once(): void
    {
        $this->writeIndex([[1, 0, 0]]);
        $this->fakeVectorsByInput(['a' => [1, 0, 0]]);

        (new KbRetriever)->search(['a', ' a ']);

        Http::assertSent(fn ($request) => $request['input'] === ['a']);
    }

    public function test_each_chunk_keeps_its_better_score_and_records_which_search_found_it(): void
    {
        // the follow-up alone points nowhere useful; the context search finds chunk 1
        $this->writeIndex([[1, 0, 0], [0, 1, 0], [0, 0, 1]]);
        $this->fakeVectorsByInput([
            'und warum?' => [0.2, 0.0, 0.98],
            "what is a t-test?\nund warum?" => [0.0, 1.0, 0.0],
        ]);

        $hits = (new KbRetriever)->search(['und warum?', "what is a t-test?\nund warum?"]);

        $this->assertSame(['testdoc-001', 'testdoc-002'], array_column($hits, 'id'));
        $this->assertSame([1, 0], array_column($hits, 'q'));
        $this->assertEqualsWithDelta(1.0, $hits[0]['score'], 0.0001);
    }

    public function test_a_self_contained_new_question_is_not_diluted_by_the_previous_one(): void
    {
        // topic switch: the latest message alone matches chunk 2 perfectly and must stay on top
        $this->writeIndex([[1, 0, 0], [0, 1, 0], [0, 0, 1]]);
        $this->fakeVectorsByInput([
            'what is a chi-square test?' => [0, 0, 1],
            "what is a t-test?\nwhat is a chi-square test?" => [0.8, 0, 0.6],
        ]);

        $hits = (new KbRetriever)->search(['what is a chi-square test?', "what is a t-test?\nwhat is a chi-square test?"]);

        $this->assertSame('testdoc-002', $hits[0]['id']);
        $this->assertSame(0, $hits[0]['q']);
    }

    public function test_a_vector_count_mismatch_degrades_instead_of_misaligning_queries(): void
    {
        $this->writeIndex([[1, 0, 0]]);
        $this->fakeQueryVector([1, 0, 0]); // one vector for two inputs

        $this->assertSame([], (new KbRetriever)->search(['a', 'b']));
    }

    /**
     * 60 chunks in 8 dims whose second half repeats the first, so a chunk's
     * 4-dim prefix cosine equals its full cosine and the first pass cannot drop
     * a true top-3 chunk. The two-step scan must then return exactly what the
     * full scan returns.
     */
    public function test_the_two_step_scan_returns_the_same_hits_as_a_full_scan(): void
    {
        mt_srand(42);
        $vectors = [];
        for ($i = 0; $i < 60; $i++) {
            $u = [mt_rand() / mt_getrandmax() - 0.5, mt_rand() / mt_getrandmax() - 0.5,
                mt_rand() / mt_getrandmax() - 0.5, mt_rand() / mt_getrandmax() - 0.5];
            $v = array_merge($u, $u);
            $n = sqrt(array_sum(array_map(fn ($x) => $x * $x, $v)));
            $vectors[] = array_map(fn ($x) => $x / $n, $v);
        }
        $this->writeIndex($vectors, ['dims' => 8]);
        config(['tutor.embed.dims' => 8, 'tutor.min_score' => -1.0, 'tutor.rescore_pool' => 10, 'tutor.coarse_dims' => 4]);
        $q = [0.3, -0.2, 0.5, 0.1, 0.3, -0.2, 0.5, 0.1];

        $this->fakeQueryVector($q);
        $twoStep = (new KbRetriever)->search('q');

        config(['tutor.coarse_dims' => 0]);
        $this->fakeQueryVector($q);
        $full = (new KbRetriever)->search('q');

        $this->assertCount(3, $full);
        $this->assertSame(array_column($full, 'id'), array_column($twoStep, 'id'));
        $this->assertSame(array_column($full, 'score'), array_column($twoStep, 'score'));
    }

    public function test_the_two_step_scan_applies_the_floor_to_exact_full_width_scores(): void
    {
        // chunk 0's prefix matches the query perfectly but its full vector does not:
        // the first pass shortlists it, and the exact score must still keep it out
        $s = 1 / sqrt(2);
        $vectors = [[$s, 0, -$s, 0], [0, $s, 0, $s]];
        for ($i = 0; $i < 3; $i++) {
            $vectors[] = [0, 0, 0, 1];
        }
        $this->writeIndex($vectors, ['dims' => 4]);
        config(['tutor.embed.dims' => 4, 'tutor.rescore_pool' => 2, 'tutor.coarse_dims' => 2]);
        $this->fakeQueryVector([$s, 0, $s, 0]);

        $hits = (new KbRetriever)->search('q');

        $this->assertNotContains('testdoc-000', array_column($hits, 'id'),
            'a chunk whose exact cosine is 0 must not pass the floor on its prefix score');
    }

    public function test_citation_parts_travel_with_the_hit_when_the_index_has_them(): void
    {
        $this->writeIndex([[1, 0, 0], [0, 1, 0]]);
        $meta = json_decode(file_get_contents($this->base.'.json'), true);
        $meta['chunks'][0] += [
            'citation' => 'Statistik I · Vorlesung 14: t-Test · Folie 11',
            'cite_course' => 'Statistik I', 'cite_doc' => 'Vorlesung 14',
            'cite_topic' => 't-Test', 'cite_pos' => 'Folie 11',
        ];
        file_put_contents($this->base.'.json', json_encode($meta));
        config(['tutor.min_score' => 0.0]);
        $this->fakeQueryVector([0.8, 0.6, 0]);

        $hits = (new KbRetriever)->search('anything');

        $this->assertSame(
            ['course' => 'Statistik I', 'doc' => 'Vorlesung 14', 'topic' => 't-Test', 'pos' => 'Folie 11'],
            $hits[0]['cite']
        );
        $this->assertNull($hits[1]['cite'], 'chunks without parts carry none');
    }

    public function test_status_reports_a_loadable_index_and_its_version(): void
    {
        $this->writeIndex([[1, 0, 0], [0, 1, 0]]);

        $s = (new KbRetriever)->status();

        $this->assertTrue($s['ok']);
        $this->assertSame('testdoc@2026-09-02T00:00:00+00:00', $s['version']);
        $this->assertSame(2, $s['chunks']);
        $this->assertSame(hash_file('sha256', $this->base.'.json'), $s['sha256']);
    }

    public function test_status_reports_a_broken_index_without_throwing(): void
    {
        $this->writeIndex([[1, 0, 0]], ['model' => 'text-embedding-3-small']);

        $s = (new KbRetriever)->status();

        $this->assertFalse($s['ok']);
        $this->assertStringContainsString('text-embedding-3-small', $s['error']);
    }

    public function test_kb_status_command_fails_when_the_index_cannot_load(): void
    {
        $this->artisan('kb:status')->assertExitCode(1);

        $this->writeIndex([[1, 0, 0]]);
        $this->artisan('kb:status')->expectsOutputToContain('index OK: testdoc@')->assertExitCode(0);
    }
}
