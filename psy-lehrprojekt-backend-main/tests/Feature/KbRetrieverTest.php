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
}
