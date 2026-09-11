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

];
