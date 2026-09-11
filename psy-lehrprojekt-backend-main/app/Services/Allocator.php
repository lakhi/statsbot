<?php

namespace App\Services;

use App\Models\Student;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Binds a student to a trial arm, once, at the moment they accept the
 * disclaimer.
 *
 * This class deliberately knows NOTHING about what the arms do. It never
 * mentions retrieval, prompts or the knowledge base, and nothing here should
 * ever import KbRetriever or TutorPrompt. Keeping the randomiser blind to the
 * treatment is what stops the allocation from accidentally depending on it -
 * the same reason a trial uses a separate randomisation service rather than
 * letting the treating clinician compute the assignment.
 *
 * It also means the treatment can keep changing (the answer shape, the prompt,
 * the retrieval parameters) without touching a line of this file.
 */
class Allocator
{
    /** Allocation only happens in a running phase. 'off' leaves arm = NULL. */
    public static function enabled(): bool
    {
        return in_array(config('study.phase'), ['test', 'study'], true);
    }

    /**
     * Degree level for this uid, or the unknown stratum when the roster has no
     * row. Absence is a normal outcome, not an error: the student is allocated
     * and flagged, never turned away.
     */
    public static function stratumFor(string $uid): string
    {
        return DB::table('roster')->where('uid', $uid)->value('status')
            ?? (string) config('study.unknown_stratum');
    }

    /**
     * Claim the next slot in this student's stratum and bind it to them.
     *
     * Idempotent: a student who already holds an arm keeps it. That matters
     * more than it looks - a double-submitted disclaimer must not consume two
     * slots, and must never re-randomise someone who has already started.
     *
     * Returns the arm, or null when the phase is 'off'.
     */
    public static function allocate(Student $student): ?string
    {
        if (! self::enabled()) {
            return null;
        }

        return DB::transaction(function () use ($student) {
            $phase = (string) config('study.phase');

            //Serialise concurrent registers for the SAME uid. Without this two
            //simultaneous disclaimer submissions both read arm = NULL, both
            //claim, and one loses to the unique(phase, claimed_uid) index with
            //an exception rather than simply returning the arm it already has.
            $locked = Student::where('id', $student->id)->lockForUpdate()->first();

            if ($locked && $locked->arm !== null) {
                $student->arm = $locked->arm;
                $student->allocated_at = $locked->allocated_at;

                return $locked->arm;
            }

            $stratum = self::stratumFor($student->uid);

            $query = DB::table('allocation_slot')
                ->where('phase', $phase)
                ->where('stratum', $stratum)
                ->whereNull('claimed_uid')
                ->orderBy('seq');

            //SKIP LOCKED keeps two students arriving in the same second from
            //queueing on the same row; MariaDB 11.4 on the pod supports it.
            //SQLite (the test connection) has no such syntax and serialises
            //writes anyway, so the hint is simply omitted there.
            if (DB::connection()->getDriverName() === 'mysql') {
                $query->lock('FOR UPDATE SKIP LOCKED');
            }

            $slot = $query->first();

            if (! $slot) {
                //Improvising an allocation here would be far worse than an
                //outage: it would produce a participant who is in the study but
                //not in the randomisation list. Seed the sequence generously and
                //treat this as the alarm it is.
                Log::error('Allocator: sequence exhausted', [
                    'phase' => $phase,
                    'stratum' => $stratum,
                ]);

                abort(503, 'allocation sequence exhausted');
            }

            DB::table('allocation_slot')
                ->where('id', $slot->id)
                ->update([
                    'claimed_uid' => $student->uid,
                    'claimed_at' => now(),
                    'updated_at' => now(),
                ]);

            $student->arm = $slot->arm;
            $student->allocated_at = now();

            //saved HERE, inside the claim transaction. Stamping the arm outside
            //it would let a crash between the two leave a claimed slot whose
            //student has no arm - a participant in the randomisation list but
            //not in the study.
            $student->save();

            Log::info('Allocator: slot claimed', [
                'phase' => $phase,
                'stratum' => $stratum,
                'seq' => $slot->seq,
                'arm' => $slot->arm,
            ]);

            return $slot->arm;
        });
    }
}
