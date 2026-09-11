<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the 'test' randomisation sequence used to rehearse all four buckets
 * with two logins.
 *
 * The sequence is chosen so that the most likely implementation bug produces a
 * VISIBLY wrong result rather than a plausible one: bachelor and master start
 * on opposite arms, so a version that kept one global sequence instead of one
 * per stratum would hand the same arm to run 1 and run 3.
 *
 * No 'unknown' slots are seeded, deliberately. A roster lookup that misfires
 * then aborts with 503 instead of quietly allocating from a fallback sequence.
 */
class StudySeedTest extends Command
{
    protected $signature = 'study:seed-test {--force : reseed even if test slots already exist}';

    protected $description = 'Seed the test-phase allocation sequence';

    /** Blocks of 4, opposite openings per stratum. */
    private const SEQUENCES = [
        'bachelor' => ['rag', 'no_rag', 'no_rag', 'rag'],
        'master' => ['no_rag', 'rag', 'rag', 'no_rag'],
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

        DB::transaction(function () {
            DB::table('allocation_slot')->where('phase', 'test')->delete();

            foreach (self::SEQUENCES as $stratum => $arms) {
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

        foreach (self::SEQUENCES as $stratum => $arms) {
            $this->line(sprintf('%-9s %s', $stratum, implode(', ', $arms)));
        }

        $this->info('Test sequence seeded.');

        return self::SUCCESS;
    }
}
