<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Trial phase
    |--------------------------------------------------------------------------
    |
    | off   - no allocation happens; /register behaves exactly as it did before
    |         and every student keeps arm = NULL.
    | test  - allocation runs against the 'test' sequence, and the reset
    |         endpoint is enabled.
    | study - allocation runs against the 'study' sequence. Reset is refused.
    |
    | The reset refusal is the important one: releasing slots mid-study would
    | silently re-randomise a participant, which is the one failure in this
    | system that invalidates data without producing an error.
    |
    */

    'phase' => env('STUDY_PHASE', 'off'),

    /*
    | Comma-separated u:account IDs allowed to read the allocation monitor and
    | (in the test phase) trigger a reset. Shibboleth has already authenticated
    | the uid by the time the request reaches Laravel, so an allowlist is the
    | whole access check - there is no second credential to get wrong.
    */

    'admin_uids' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('STUDY_ADMIN_UIDS', ''))
    ))),

    /*
    | Stratum used when a uid is absent from the roster (late enrolment, staff,
    | a curious colleague). They are allocated normally and flagged, never
    | turned away; the pre-registration excludes them from the primary analysis.
    */

    'unknown_stratum' => 'unknown',

    /*
    |--------------------------------------------------------------------------
    | Arms
    |--------------------------------------------------------------------------
    |
    | Three arms, but two factors - there is no "citations without retrieval"
    | cell:
    |
    |                     retrieves   shows sources
    |   no_rag            no          no
    |   rag_nonsalient    yes         no
    |   rag_salient       yes         yes
    |
    | Code asks the factor question (App\Support\StudyArm::retrieves() /
    | showsSources()), never "which arm is this". That is what keeps the two RAG
    | arms the same tutor: up to the moment the answer is displayed, nothing can
    | tell them apart - same retrieval, same prompt, byte-identical Azure call.
    |
    | Keys are the values stored in allocation_slot.arm, students.arm and
    | history.arm. A NULL or unlisted arm (every pre-trial student, or the
    | two-arm pilot's old 'rag') gets control behaviour.
    |
    */

    'arms' => [
        'no_rag' => ['retrieves' => false, 'shows_sources' => false],
        'rag_nonsalient' => ['retrieves' => true, 'shows_sources' => false],
        'rag_salient' => ['retrieves' => true, 'shows_sources' => true],
    ],

    /*
    | Internal testing only. Lets the per-arm test URLs (/rag-pilot-test/salient/
    | etc.) pin the arm for a request, so one tester can try all three.
    |
    | A pinned arm is REFUSED (403), not ignored, whenever this is false or the
    | phase is 'study'. Ignoring it would quietly answer a tester under the wrong
    | arm; honouring it in the study would let a student switch arms.
    */

    'arm_override' => (bool) env('STUDY_ARM_OVERRIDE', false),

];
