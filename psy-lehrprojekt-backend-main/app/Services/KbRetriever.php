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
 * offline in tools/kb/. At request time this does exactly two things: one
 * embeddings call for the student's question, and a dot product against the
 * pre-normalised index. Benchmarked at 9-55ms for corpora far larger than the
 * pilot's - noise beside a multi-second chat completion.
 */
class KbRetriever
{
    /** @var array<string,mixed>|null */
    private ?array $meta = null;

    /** @var string|null raw float32 blob, N * dims * 4 bytes */
    private ?string $blob = null;

    /**
     * Retrieve the passages most similar to $query.
     *
     * Returns [] rather than throwing when retrieval is unavailable: a broken
     * index or a flaky embeddings call must degrade StatsBot to its previous
     * ungrounded behaviour, never take the tutor offline.
     *
     * @return array<int,array<string,mixed>>
     */
    public function search(string $query): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        try {
            $this->load();
            $vector = $this->embed($query);

            return $this->rank($vector);
        } catch (\Throwable $e) {
            Log::warning('KbRetriever unavailable, answering ungrounded', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }
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

        $this->meta = $meta;
        $this->blob = $blob;
    }

    /** @return array<int,float> */
    private function embed(string $query): array
    {
        $cfg = config('tutor.embed');

        $url = rtrim($cfg['endpoint'], '/')
            .'/openai/deployments/'.$cfg['deployment']
            .'/embeddings?api-version='.$cfg['api_version'];

        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
            'api-key' => env('AZURE_API_KEY', 'no_key_available'),
        ])->timeout($cfg['timeout'])->post($url, [
            'input' => $query,
            'dimensions' => (int) $cfg['dims'],
        ]);

        if (! $response->successful()) {
            throw new RuntimeException('embeddings call failed: HTTP '.$response->status());
        }

        $vector = $response->json('data.0.embedding');
        if (! is_array($vector) || count($vector) !== (int) $cfg['dims']) {
            throw new RuntimeException('embeddings call returned an unexpected vector');
        }

        // The index is stored L2-normalised so scoring is a bare dot product;
        // the query has to be normalised the same way for that to be a cosine.
        return $this->normalise($vector);
    }

    /**
     * Brute-force cosine over the whole index.
     *
     * No ANN structure, on purpose: a course corpus is thousands of chunks, not
     * millions, and an exact scan at this size costs less than the surrounding
     * HTTP call. unpack() at an offset avoids copying each row out of the blob.
     *
     * @param  array<int,float>  $q
     * @return array<int,array<string,mixed>>
     */
    private function rank(array $q): array
    {
        $dims = (int) $this->meta['dims'];
        $count = (int) $this->meta['count'];
        $stride = $dims * 4;
        $minScore = (float) config('tutor.min_score');

        $scores = [];
        for ($i = 0; $i < $count; $i++) {
            $row = unpack("g{$dims}", $this->blob, $i * $stride);
            $sum = 0.0;
            for ($j = 1; $j <= $dims; $j++) {
                $sum += $q[$j - 1] * $row[$j];
            }
            if ($sum >= $minScore) {
                $scores[$i] = $sum;
            }
        }

        arsort($scores);
        $hits = [];

        foreach (array_slice($scores, 0, (int) config('tutor.top_k'), true) as $i => $score) {
            $chunk = $this->meta['chunks'][$i];
            $hits[] = [
                'id' => $chunk['id'],
                'heading' => implode(' › ', $chunk['heading_chain'] ?: [$chunk['doc_title']]),
                'doc_title' => $chunk['doc_title'],
                'page_start' => $chunk['page_start'],
                'page_end' => $chunk['page_end'],
                'text' => $chunk['text'],
                'score' => round($score, 4),
            ];
        }

        return $hits;
    }

    /** @param array<int,float> $v
     *  @return array<int,float> */
    private function normalise(array $v): array
    {
        $sum = 0.0;
        foreach ($v as $x) {
            $sum += $x * $x;
        }
        $norm = sqrt($sum);
        if ($norm <= 0.0) {
            return $v;
        }

        return array_map(static fn ($x) => $x / $norm, $v);
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
}
