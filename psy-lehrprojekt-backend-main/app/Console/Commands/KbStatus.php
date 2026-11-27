<?php

namespace App\Console\Commands;

use App\Services\KbRetriever;
use Illuminate\Console\Command;

/**
 * Says whether the course-material index loads, and which corpus it is.
 *
 * Exists because KbRetriever fails soft on purpose: a missing, truncated or
 * mismatched index turns both RAG arms into no-RAG without a single error a
 * student or the deploy log would see. rag-pilot-deploy.sh runs this after
 * every deploy; run it by hand on the pod whenever in doubt. No Azure call is
 * made - it only loads and checks the files.
 *
 * Exit code 1 when the index cannot be loaded.
 */
class KbStatus extends Command
{
    protected $signature = 'kb:status {--json : print the status as JSON}';

    protected $description = 'Check that the RAG index loads, and print which corpus it is';

    public function handle(KbRetriever $retriever): int
    {
        $s = $retriever->status();

        if ($this->option('json')) {
            $this->line(json_encode($s, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $s['ok'] ? self::SUCCESS : self::FAILURE;
        }

        if (! $s['ok']) {
            $this->error('INDEX NOT LOADABLE - both RAG arms are answering ungrounded');
            $this->line('  path:  '.$s['path']);
            $this->line('  error: '.$s['error']);

            return self::FAILURE;
        }

        $this->info('index OK: '.$s['version']);
        $this->line(sprintf('  %d chunks, %d with citations, %s @ %d dims',
            $s['chunks'], $s['with_citations'], $s['model'], $s['dims']));
        $this->line('  sidecar sha256: '.$s['sha256']);
        $this->line('  path: '.$s['path']);
        if (! config('tutor.rag_enabled')) {
            $this->warn('  RAG_ENABLED is false - the index is not being used');
        }

        return self::SUCCESS;
    }
}
