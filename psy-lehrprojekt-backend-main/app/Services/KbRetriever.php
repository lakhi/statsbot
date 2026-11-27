<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Semantic search over the course-material index.
 *
 * Deliberately dependency-free: pack/unpack, file_get_contents and Laravel's own
 * Http client, all of which the pod already has. The webspace container has no
 * Composer, so anything requiring `composer install` cannot be deployed there.
 *
 * All the expensive work (PDF extraction, chunking, corpus embedding) happens
 * offline in tools/kb/. At request time this does exactly two things: ONE
 * embeddings call (carrying every query of the turn), and a dot-product scan
 * against the pre-normalised index.
 *
 * Measured with PHP 8.2 on 2,500 chunks x 3072 dims: a plain exact scan is
 * ~340 ms per query, while the two-step scan below costs ~60 ms for two queries.
 */
class KbRetriever
{
    /** @var array<string,mixed>|null */
    private ?array $meta = null;

    /** @var string|null raw float32 blob, N * dims * 4 bytes */
    private ?string $blob = null;

    /**
     * Retrieve the passages most similar to any of $queries.
     *
     * The /messages route passes two queries: the student's latest message on
     * its own, and the previous student message plus the latest. Every chunk
     * keeps the better of its scores against them, so a self-contained question
     * is never diluted by an earlier one, while a follow-up ("und warum?") still
     * finds the topic it refers to. Each hit records which query matched it (`q`,
     * the index into $queries) so the two can be told apart in history.kb_chunks.
     *
     * Returns [] rather than throwing when retrieval is unavailable: a broken
     * index or a flaky embeddings call must degrade StatsBot to its previous
     * ungrounded behaviour, never take the tutor offline.
     *
     * @param  string|array<int,string>  $queries
     * @return array<int,array<string,mixed>>
     */
    public function search(string|array $queries): array
    {
        $queries = array_values(array_unique(array_filter(
            array_map(static fn ($q) => trim((string) $q), (array) $queries),
            static fn ($q) => $q !== ''
        )));
        if ($queries === []) {
            return [];
        }

        try {
            $this->load();

            return $this->rank($this->embed($queries));
        } catch (\Throwable $e) {
            Log::warning('KbRetriever unavailable, answering ungrounded', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * The searches for one turn: [latest] on a student's first message, else
     * [latest, previous + "\n" + latest]. Only STUDENT messages are used - the
     * tutor's replies are long and would outweigh the question in the vector.
     * Two, not three: the second search is the only one carrying context, and
     * each older message pulls it further from what is being asked now.
     *
     * @param  array<int,array{role?:string,content?:string}>  $messages
     * @return array<int,string>
     */
    public static function queriesFor(array $messages): array
    {
        $student = array_values(array_filter(
            $messages,
            static fn ($m) => ($m['role'] ?? null) === 'user' && trim((string) ($m['content'] ?? '')) !== ''
        ));
        if ($student === []) {
            return [];
        }

        $latest = trim((string) end($student)['content']);
        if (count($student) < 2) {
            return [$latest];
        }
        $previous = trim((string) $student[count($student) - 2]['content']);

        return [$latest, $previous."\n".$latest];
    }

    /**
     * Load the index and verify it was built by the model we are about to query
     * with. Without this check, pointing AZURE_EMBED_DEPLOYMENT at a different
     * model returns vectors from a different space: every score is meaningless
     * but nothing errors, so the tutor would cite arbitrary passages as the
     * course's position. Fail loudly instead.
     */
    private function load(): void
    {
        if ($this->blob !== null) {
            return;
        }

        $base = config('tutor.index_path');
        $metaPath = $base.'.json';
        $vecPath = $base.'.f32';

        if (! is_readable($metaPath) || ! is_readable($vecPath)) {
            throw new RuntimeException("index not readable at {$base}.{json,f32}");
        }

        $meta = json_decode(file_get_contents($metaPath), true, 512, JSON_THROW_ON_ERROR);
        $want = config('tutor.embed');

        if (($meta['model'] ?? null) !== $want['model']) {
            throw new RuntimeException(
                "index model {$meta['model']} != configured {$want['model']} - rebuild the index"
            );
        }
        if ((int) ($meta['dims'] ?? 0) !== (int) $want['dims']) {
            throw new RuntimeException(
                "index dims {$meta['dims']} != configured {$want['dims']} - rebuild the index"
            );
        }
        if (! ($meta['normalised'] ?? false)) {
            throw new RuntimeException('index is not L2-normalised; scoring assumes it is');
        }

        $blob = file_get_contents($vecPath);
        $expected = (int) $meta['count'] * (int) $meta['dims'] * 4;
        if (strlen($blob) !== $expected) {
            throw new RuntimeException(
                'index truncated: expected '.$expected.' bytes, found '.strlen($blob)
            );
        }
        if (count($meta['chunks'] ?? []) !== (int) $meta['count']) {
            throw new RuntimeException('index sidecar lists '.count($meta['chunks'] ?? []).' chunks, count says '.$meta['count']);
        }

        $this->meta = $meta;
        $this->blob = $blob;
    }

    /**
     * One embeddings request for every query of the turn - the API takes an
     * array input, so a second query costs no extra round trip.
     *
     * @param  array<int,string>  $queries
     * @return array<int,array<int,float>> normalised vectors, in $queries order
     */
    private function embed(array $queries): array
    {
        $cfg = config('tutor.embed');

        $url = rtrim($cfg['endpoint'], '/')
            .'/openai/deployments/'.$cfg['deployment']
            .'/embeddings?api-version='.$cfg['api_version'];

        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
            'api-key' => env('AZURE_API_KEY', 'no_key_available'),
        ])->timeout($cfg['timeout'])->post($url, [
            'input' => $queries,
            'dimensions' => (int) $cfg['dims'],
        ]);

        if (! $response->successful()) {
            throw new RuntimeException('embeddings call failed: HTTP '.$response->status());
        }

        $data = $response->json('data');
        if (! is_array($data) || count($data) !== count($queries)) {
            throw new RuntimeException('embeddings call returned '.(is_array($data) ? count($data) : 0)
                .' vectors for '.count($queries).' inputs');
        }
        // The API documents `index` as the input position; never trust array order.
        usort($data, static fn ($a, $b) => ($a['index'] ?? 0) <=> ($b['index'] ?? 0));

        $vectors = [];
        foreach ($data as $item) {
            $vector = $item['embedding'] ?? null;
            if (! is_array($vector) || count($vector) !== (int) $cfg['dims']) {
                throw new RuntimeException('embeddings call returned an unexpected vector');
            }
            // The index is stored L2-normalised so scoring is a bare dot product;
            // the query has to be normalised the same way for that to be a cosine.
            $vectors[] = self::normalise($vector);
        }

        return $vectors;
    }

    /**
     * Exact cosine ranking, after an optional cheap first pass.
     *
     * text-embedding-3-* vectors are Matryoshka-trained: their leading
     * dimensions are a usable embedding on their own once renormalised. So the
     * first pass scores every chunk on its first `coarse_dims` values only (the
     * same blob, read at an offset - no second file), keeps the best
     * `rescore_pool`, and only those are scored at full width. The scores that
     * reach the floor, the prompt and history are therefore always exact
     * full-width cosines; the first pass only decides which chunks get scored.
     * Small indexes (count <= pool) skip it and are scanned exactly.
     *
     * @param  array<int,array<int,float>>  $qs
     * @return array<int,array<string,mixed>>
     */
    private function rank(array $qs): array
    {
        $dims = (int) $this->meta['dims'];
        $count = (int) $this->meta['count'];
        $stride = $dims * 4;
        $minScore = (float) config('tutor.min_score');
        $pool = (int) config('tutor.rescore_pool', 100);
        $coarseDims = (int) config('tutor.coarse_dims', 256);

        $candidates = ($count > $pool && $coarseDims > 0 && $coarseDims < $dims)
            ? $this->shortlist($qs, $coarseDims, $pool)
            : range(0, $count - 1);

        $scores = [];
        $matched = [];
        foreach ($candidates as $i) {
            $row = unpack("g{$dims}", $this->blob, $i * $stride);
            foreach ($qs as $k => $q) {
                $sum = 0.0;
                for ($j = 1; $j <= $dims; $j++) {
                    $sum += $q[$j - 1] * $row[$j];
                }
                if (! isset($scores[$i]) || $sum > $scores[$i]) {
                    $scores[$i] = $sum;
                    $matched[$i] = $k;
                }
            }
        }

        $scores = array_filter($scores, static fn ($s) => $s >= $minScore);
        arsort($scores);
        $hits = [];

        foreach (array_slice($scores, 0, (int) config('tutor.top_k'), true) as $i => $score) {
            $chunk = $this->meta['chunks'][$i];
            $heading = implode(' › ', ($chunk['heading_chain'] ?? []) ?: [$chunk['doc_title'] ?? '']);
            $hits[] = [
                'id' => $chunk['id'],
                'heading' => $heading,
                'label' => self::label($chunk, $heading),
                'cite' => self::citeParts($chunk),
                'doc_title' => $chunk['doc_title'] ?? null,
                'page_start' => $chunk['page_start'] ?? null,
                'page_end' => $chunk['page_end'] ?? null,
                'text' => $chunk['text'],
                'score' => round($score, 4),
                'q' => $matched[$i],
            ];
        }

        return $hits;
    }

    /**
     * First pass: the `$pool` chunks whose leading `$d` dimensions sit closest to
     * any query. Each row's prefix is renormalised on the fly (its norm is summed
     * in the same loop), so the index format needs nothing extra.
     *
     * @param  array<int,array<int,float>>  $qs
     * @return array<int,int> row numbers
     */
    private function shortlist(array $qs, int $d, int $pool): array
    {
        $count = (int) $this->meta['count'];
        $stride = (int) $this->meta['dims'] * 4;
        $prefixes = array_map(static fn ($q) => self::normalise(array_slice($q, 0, $d)), $qs);

        $coarse = [];
        for ($i = 0; $i < $count; $i++) {
            $row = unpack("g{$d}", $this->blob, $i * $stride);
            $norm = 0.0;
            for ($j = 1; $j <= $d; $j++) {
                $norm += $row[$j] * $row[$j];
            }
            if ($norm <= 0.0) {
                continue;
            }
            $best = -INF;
            foreach ($prefixes as $p) {
                $sum = 0.0;
                for ($j = 1; $j <= $d; $j++) {
                    $sum += $p[$j - 1] * $row[$j];
                }
                $best = max($best, $sum);
            }
            $coarse[$i] = $best / sqrt($norm);
        }

        arsort($coarse);

        return array_keys(array_slice($coarse, 0, $pool, true));
    }

    /**
     * The citation a salient-arm student sees, e.g.
     * "Statistik I · Vorlesung 14: t-Test · Folie 11".
     *
     * The ingestion pipeline writes it per chunk as `citation`, because only it
     * knows what a page IS - a slide, a handout page, an exam question. Indexes
     * built before that field existed (the hyptest pilot) fall back to heading
     * and page.
     *
     * @param  array<string,mixed>  $chunk
     */
    private static function label(array $chunk, string $heading): string
    {
        $citation = trim((string) ($chunk['citation'] ?? ''));

        return $citation !== '' ? $citation : $heading.', p. '.($chunk['page_start'] ?? '?');
    }

    /**
     * The label's parts, so the chip can shorten the topic alone and never the
     * part a student needs to find the slide. Null for indexes without them.
     *
     * @param  array<string,mixed>  $chunk
     * @return array{course:string,doc:string,topic:?string,pos:string}|null
     */
    private static function citeParts(array $chunk): ?array
    {
        $course = trim((string) ($chunk['cite_course'] ?? ''));
        $doc = trim((string) ($chunk['cite_doc'] ?? ''));
        $pos = trim((string) ($chunk['cite_pos'] ?? ''));
        if ($course === '' || $doc === '' || $pos === '') {
            return null;
        }
        $topic = trim((string) ($chunk['cite_topic'] ?? ''));

        return ['course' => $course, 'doc' => $doc, 'topic' => $topic !== '' ? $topic : null, 'pos' => $pos];
    }

    /** @param array<int,float> $v
     *  @return array<int,float> */
    private static function normalise(array $v): array
    {
        $sum = 0.0;
        foreach ($v as $x) {
            $sum += $x * $x;
        }
        $norm = sqrt($sum);
        if ($norm <= 0.0) {
            return array_values($v);
        }

        return array_values(array_map(static fn ($x) => $x / $norm, $v));
    }

    /** Index provenance, for logging which corpus answered. */
    public function version(): ?string
    {
        try {
            $this->load();
        } catch (\Throwable) {
            return null;
        }

        return ($this->meta['doc_id'] ?? '?').'@'.($this->meta['built_at'] ?? '?');
    }

    /**
     * What `php artisan kb:status` and the study monitor report: whether the
     * index loads, and which corpus it is. Never throws - a broken index is
     * the thing this exists to surface.
     *
     * @return array<string,mixed>
     */
    public function status(): array
    {
        $base = (string) config('tutor.index_path');
        try {
            $this->load();
        } catch (\Throwable $e) {
            return ['ok' => false, 'path' => $base, 'error' => $e->getMessage()];
        }

        return [
            'ok' => true,
            'path' => $base,
            'version' => $this->version(),
            'doc_id' => $this->meta['doc_id'] ?? null,
            'built_at' => $this->meta['built_at'] ?? null,
            'model' => $this->meta['model'],
            'dims' => (int) $this->meta['dims'],
            'chunks' => (int) $this->meta['count'],
            'with_citations' => count(array_filter($this->meta['chunks'], static fn ($c) => ! empty($c['citation']))),
            'sha256' => hash_file('sha256', $base.'.json'),
        ];
    }
}
