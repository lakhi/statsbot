<?php

namespace App\Console\Commands;

use App\Services\Allocator;
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
        {--force : reseed even if test slots already exist; releases every test allocation first}
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

        $sequences = self::SEQUENCES;
        if ($this->option('with-unknown')) {
            $sequences[(string) config('study.unknown_stratum')] = self::UNKNOWN;
        }

        //An unconfigured arm would be claimed and then answered as control in
        //silence (StudyArm's fallback). Refuse it here, where it is cheap.
        $unknown = array_diff(array_merge(...array_values($sequences)), array_keys(config('study.arms')));
        if ($unknown !== []) {
            $this->error('not configured in study.arms: '.implode(', ', array_unique($unknown)));

            return self::FAILURE;
        }

        //Release BEFORE reseeding: deleting claimed rows under students who still
        //hold an arm would leave them allocated to a slot that no longer exists.
        //Doing it here, rather than requiring POST /study/reset first, makes the
        //pod procedure one artisan line.
        try {
            $released = Allocator::releaseTestPhase();
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($released !== []) {
            $this->line('released (back to the disclaimer): '.implode(', ', $released));
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
