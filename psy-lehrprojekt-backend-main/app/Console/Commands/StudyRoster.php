<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Upserts one roster row: the stratum lookup used at allocation time.
 *
 * During the test phase this is how two logins cover four buckets - the
 * stratum is a table row, not a property of the person, so the same uid is
 * re-pointed from bachelor to master between runs.
 */
class StudyRoster extends Command
{
    protected $signature = 'study:roster {uid} {status : bachelor|master} {--provenance=test-phase}';

    protected $description = 'Set a uid\'s program level in the roster';

    public function handle(): int
    {
        $status = $this->argument('status');

        if (! in_array($status, ['bachelor', 'master'], true)) {
            $this->error('status must be bachelor or master');

            return self::FAILURE;
        }

        $uid = $this->argument('uid');

        $values = [
            'status' => $status,
            'provenance' => $this->option('provenance'),
            'updated_at' => now(),
        ];

        //only stamp created_at on a genuine insert - re-pointing a uid between
        //runs should not look like a new roster entry
        if (! DB::table('roster')->where('uid', $uid)->exists()) {
            $values['created_at'] = now();
        }

        DB::table('roster')->updateOrInsert(['uid' => $uid], $values);

        $this->info($uid.' -> '.$status);

        return self::SUCCESS;
    }
}
