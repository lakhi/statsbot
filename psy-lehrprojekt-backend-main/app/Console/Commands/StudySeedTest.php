<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the 'test' randomisation sequence used to rehearse allocation.
 *
 * Permuted blocks of six - two of each of the three arms - with a different
 * opening arm per stratum. That keeps the old design goal: the most likely
 * implementation bug produces a VISIBLY wrong result rather than a plausible
 * one. A version that kept one global sequence instead of one per stratum would
 * hand bachelor's second claimant master's opening arm.
 *
 * No 'unknown' slots by default, deliberately: a roster lookup that misfires
 * then aborts with 503 instead of quietly allocating from a fallback sequence.
 * --with-unknown seeds them anyway, for an internal test round in which
 * teammates who are not on the roster need to get past the disclaimer.
 */
class StudySeedTest extends Command
{
    protected $signature = 'study:seed-test
        {--force : reseed even if test slots already exist}
        {--with-unknown : also seed the unknown stratum, for testers not on the roster}';

    protected $description = 'Seed the test-phase allocation sequence';

    private const SEQUENCES = [
        'bachelor' => ['rag_salient', 'no_rag', 'rag_nonsalient', 'rag_nonsalient', 'rag_salient', 'no_rag'],
        'master' => ['no_rag', 'rag_nonsalient', 'rag_salient', 'rag_salient', 'no_rag', 'rag_nonsalient'],
    ];

    /** Two blocks: enough for a handful of teammates. */
    private const UNKNOWN = [
        'rag_nonsalient', 'rag_salient', 'no_rag', 'no_rag', 'rag_nonsalient', 'rag_salient',
        'rag_salient', 'rag_nonsalient', 'no_rag', 'rag_nonsalient', 'no_rag', 'rag_salient',
    ];

    public function handle(): int
    {
        if (config('study.phase') !== 'test') {
            $this->error('STUDY_PHASE is "'.config('study.phase').'" - refusing to seed a test sequence.');

            return self::FAILURE;
        }

        $existing = DB::table('allocation_slot')->where('phase', 'test')->count();

        if ($existing > 0 && ! $this->option('force')) {
            $this->warn("$existing test slots already exist; pass --force to reseed.");

            return self::FAILURE;
        }

        //Reseeding under students who still hold an arm would leave them in no
        //slot at all - POST /study/reset first, which releases slots AND arms.
        $held = DB::table('students')->whereNotNull('arm')->count();
        if ($held > 0) {
            $this->error("$held students still hold an arm - run POST /study/reset first.");

            return self::FAILURE;
        }

        $sequences = self::SEQUENCES;
        if ($this->option('with-unknown')) {
            $sequences[(string) config('study.unknown_stratum')] = self::UNKNOWN;
        }

        DB::transaction(function () use ($sequences) {
            DB::table('allocation_slot')->where('phase', 'test')->delete();

            foreach ($sequences as $stratum => $arms) {
                foreach ($arms as $i => $arm) {
                    DB::table('allocation_slot')->insert([
                        'phase' => 'test',
                        'stratum' => $stratum,
                        'seq' => $i + 1,
                        'arm' => $arm,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        });

        foreach ($sequences as $stratum => $arms) {
            $this->line(sprintf('%-9s %s', $stratum, implode(', ', $arms)));
        }

        $this->info('Test sequence seeded.');

        return self::SUCCESS;
    }
}
