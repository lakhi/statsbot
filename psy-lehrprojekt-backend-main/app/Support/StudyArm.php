<?php

namespace App\Support;

use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * What a trial arm DOES, read from config('study.arms').
 *
 * Callers ask a factor question - does this arm retrieve? does it show
 * sources? - and never compare arm names. See config/study.php for why.
 *
 * Allocator must never import this class. The randomiser stays blind to the
 * treatment; this is the treatment.
 */
final class StudyArm
{
    public static function known(?string $arm): bool
    {
        return $arm !== null && array_key_exists($arm, (array) config('study.arms'));
    }

    public static function retrieves(?string $arm): bool
    {
        return self::known($arm) && (bool) config('study.arms')[$arm]['retrieves'];
    }

    public static function showsSources(?string $arm): bool
    {
        return self::known($arm) && (bool) config('study.arms')[$arm]['shows_sources'];
    }

    /**
     * The arm this request is answered under: the student's allocated arm, or -
     * on the internal test stack only - the arm a test URL pinned.
     *
     * `test_arm` is validated against the arm keys by the route before this runs.
     */
    public static function effective(Request $request, Student $student): ?string
    {
        $pinned = $request->input('test_arm');

        if ($pinned === null) {
            if ($student->arm !== null && ! self::known($student->arm)) {
                //e.g. the two-arm pilot's 'rag'. Control behaviour is the safe
                //fallback, but it should never happen silently after a reseed.
                Log::warning('StudyArm: unknown arm, answering as control', ['arm' => $student->arm]);
            }

            return $student->arm;
        }

        if (! config('study.arm_override') || config('study.phase') === 'study') {
            abort(403, 'arm override is disabled');
        }

        return $pinned;
    }
}
