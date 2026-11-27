<?php

use App\Models\History;
use App\Models\Student;
use App\Services\Allocator;
use App\Services\KbRetriever;
use App\Services\TutorPrompt;
use App\Support\StudyArm;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\Rule;

Route::get('/student', function (Request $request) {

    return $request->student;

});

Route::post('/register', function (Request $request) {

    //Accepting the disclaimer IS the allocation point. It has to be: a student
    //cannot be allocated before they consent, and anything later would mean
    //routing them into an arm they had not been assigned to yet.
    //
    //Allocator::allocate() is idempotent, so a re-submitted disclaimer returns
    //the arm the student already holds instead of consuming a second slot.

    $existingStudent = Student::where('uid', $request->student->uid)->first();
    if (! empty($existingStudent)) {

        if (! $existingStudent->registered) {

            $existingStudent->registered = true;

        }

        Allocator::allocate($existingStudent);
        $existingStudent->save();

        return $existingStudent;
    } else {

        $student = new Student();
        $student->uid = $request->student->uid;
        $student->firstname = $request->student->firstname;
        $student->lastname = $request->student->lastname;
        $student->token_limit = env('TOKEN_LIMIT', 0);
        $student->token_left = env('TOKEN_LIMIT', 0);

        $student->activated = true;
        $student->registered = true;

        //saved first so the row exists to lock; allocate() then stamps the arm.
        $student->save();

        Allocator::allocate($student);
        $student->save();

        return $student;

    }

});

//These two went through raw DB::select until #4. Laravel applies the table
//prefix in the query GRAMMAR, so DB::table()/Eloquent honour DB_PREFIX and a
//raw SQL string does not. On the rag-pilot stack that meant writes landed in
//rag_history via Eloquent while reads went to the unprefixed live table - an
//empty history list, and a student_id that could collide with an unrelated
//live row. Keep these on the builder.
Route::get('/history', function (Request $request) {

    $firstOfEachThread = DB::table('history')
        ->selectRaw('MIN(id)')
        ->where('student_id', $request->student->id)
        ->groupBy('started');

    return DB::table('history')
        ->selectRaw('id, SUBSTRING(sent, 1, 200) as sent, started, created_at')
        ->whereIn('id', $firstOfEachThread)
        ->get();
});

Route::get('/history/{started}', function (Request $request, $started) {

    //Only what the chat view renders. This used to return every column, which
    //put the student's own arm, the retrieved chunk ids and the token counts in
    //the browser's network tab - enough to unblind the trial.
    //
    //`sources` is replayed from what was stamped at the time (sources_shown),
    //never recomputed, and only for rows that showed sources: a reloaded
    //salient thread looks as it did live, and every other row has no key at all
    //- exactly like a live no-RAG or non-salient answer.
    return DB::table('history')
        ->select('id', 'sent', 'received', 'started', 'created_at', 'sources_shown')
        ->where('student_id', $request->student->id)
        ->where('started', $started)
        ->orderBy('id')
        ->get()
        ->map(function ($row) {
            $out = [
                'id' => $row->id,
                'sent' => $row->sent,
                'received' => $row->received,
                'started' => $row->started,
                'created_at' => $row->created_at,
            ];

            if ($row->sources_shown !== null) {
                $out['sources'] = json_decode($row->sources_shown, true) ?? [];
            }

            return $out;
        });

});

Route::post('/messages', function (Request $request) {

    $validated = $request->validate([
        'messages.*.content' => 'required|string',
        'messages.*.role' => 'required|in:assistant,user',
        'started' => 'required|numeric',
        //test stack only - see StudyArm::effective() and config/study.php
        'test_arm' => ['nullable', 'string', Rule::in(array_keys(config('study.arms')))],
    ]);

    $messages = $request->messages;
    $student = $request->student;
    $started = $request->started;
    unset($student->registered);

    if (! $student) {
        abort(403, 'no student');
    }

    if (! $student->activated) {
        abort(403, 'not activated');
    }

    if ($student->token_left <= 0) {
        abort(403, 'no tokens left');
    }

    //Grounding is per-STUDENT, not per-deployment: the trial arm decides whether
    //this turn retrieves. config('tutor.rag_enabled') is AND-ed rather than
    //replaced, so it still works as the study-wide kill switch - set it false
    //and every arm falls back to ungrounded without moving a file.
    //
    //Both RAG arms retrieve and are prompted identically; everything from here
    //to the Azure call cannot tell salient from non-salient. They part only at
    //$shown below, after the model has answered.
    //
    //search() returns [] on any failure, so a broken index degrades the tutor
    //rather than taking it offline.
    $arm = StudyArm::effective($request, $student);
    $grounded = config('tutor.rag_enabled') && StudyArm::retrieves($arm);

    //two searches, one embeddings call: the latest message alone, and the
    //previous student message + the latest (see KbRetriever::queriesFor)
    $retriever = app(KbRetriever::class);
    $hits = $grounded
        ? $retriever->search(KbRetriever::queriesFor($messages))
        : [];

    $payload = [
        'model' => env('AZURE_MODEL', 'no_endpoint_available'),
        //system prompt first (stable prefix, so prompt caching survives),
        //retrieved passages last, as their own message - never appended to the
        //student's text, which is persisted verbatim to history.sent below.
        //$grounded, not $hits: the arm selects the system prompt, so the
        //cacheable prefix stays stable on turns that retrieve nothing.
        'messages' => TutorPrompt::assemble($messages, $hits, $grounded),
    ];

    //one coherent answer plus a separate citations array, parsed from JSON
    if ($responseFormat = TutorPrompt::responseFormat()) {
        $payload['response_format'] = $responseFormat;
    }

    //reasoning models (e.g. gpt-5-mini) reject a custom temperature and use reasoning_effort instead;
    //chat-tuned models (e.g. gpt-4o) use temperature. Switch via .env so the model can be changed
    //without code edits - leave AZURE_REASONING_EFFORT empty to fall back to a chat model.
    $reasoningEffort = env('AZURE_REASONING_EFFORT');
    if (! empty($reasoningEffort)) {
        $payload['reasoning_effort'] = $reasoningEffort; // minimal | low | medium | high
    } else {
        $payload['temperature'] = 0.7;
    }

    $gptResponse = Http::withHeaders([
        'Content-Type' => 'application/json',
        'api-key' => env('AZURE_API_KEY', 'no_key_available'),
    ])->post(env('AZURE_ENDPOINT', 'no_endpoint_available').'/openai/deployments/'.env('AZURE_DEPLOYMENT', 'no_deployment_available').'/chat/completions?api-version='.env('AZURE_API_VERSION', 'no_api_version_available'), $payload);

    $answer = TutorPrompt::parse($gptResponse['choices'][0]['message']['content'] ?? null, $hits);

    //THE point where the RAG arms differ. null = this arm displays no sources;
    //[] = salient, nothing to cite (the UI shows its "no matching course
    //material" note); otherwise the chips. The answer text is the same either way.
    $shown = StudyArm::showsSources($arm) ? TutorPrompt::displaySources($answer['sources']) : null;

    //NOTE: $messages here is still the student's own conversation - assemble()
    //worked on a copy - so history.sent stays exactly what the student typed.
    $lastSentMessage = end($messages);

    $history = new History();
    $history->student_id = $request->student->id;
    $history->sent = $lastSentMessage['content'];
    $history->started = $started;
    $history->received = $answer['answer'];
    $history->prompt_tokens = $gptResponse['usage']['prompt_tokens'];
    $history->completion_tokens = $gptResponse['usage']['completion_tokens'];
    $history->total_tokens = $gptResponse['usage']['total_tokens'];

    //which corpus answered this turn - see the add_retrieval_provenance migration.
    //Rows before and after grounding goes live are otherwise indistinguishable,
    //and that cannot be reconstructed after the fact.
    $history->kb_version = $grounded ? $retriever->version() : null;
    //q: which search matched the chunk - 0 = the latest message alone,
    //1 = previous student message + latest (KbRetriever::queriesFor)
    $history->kb_chunks = $hits ? json_encode(array_map(
        fn ($h) => ['id' => $h['id'], 'score' => $h['score'], 'q' => $h['q']], $hits
    )) : null;
    $history->grounded = $answer['sources'] !== [];

    //what the answer drew on (both RAG arms) vs what the student saw (salient
    //only) - see the add_citations_to_history migration
    $history->cited = $grounded
        ? json_encode($answer['sources'], JSON_UNESCAPED_UNICODE)
        : null;
    $history->sources_shown = $shown === null
        ? null
        : json_encode($shown, JSON_UNESCAPED_UNICODE);

    //Stamped, never joined from students.arm - see the add_arm_to_history
    //migration. A join would label this student's PRE-study messages with the
    //arm they were later allocated to. The EFFECTIVE arm, so a test-URL turn is
    //filed under the arm it was actually answered by.
    $history->arm = $arm;

    $history->save();

    $student->token_left = $student->token_left - $history->total_tokens;

    $student->save();

    //No-RAG and non-salient responses have identical keys. `grounded` and the
    //per-turn `costs` used to be here: the frontend reads neither, and both put
    //retrieval in plain sight in the network tab.
    //
    //This does NOT make the cost invisible. token_left (here and on /student)
    //still gives it as the difference of two turns, and RAG turns cost
    //1.3-2.4x more. Accepted: reading it needs DevTools AND a baseline to
    //compare against. Hiding it would mean a percentage-only budget API.
    $body = [
        'content' => $history->received,
        'token_left' => $student->token_left,
    ];

    if ($shown !== null) {
        $body['sources'] = $shown;
    }

    return response()->json($body);

});

/*
|--------------------------------------------------------------------------
| Trial operations
|--------------------------------------------------------------------------
|
| Researcher-facing, never educator-facing. No suppression floor applies here
| because nothing published from it reaches students or teaching staff - it
| exists to answer "is allocation working", which counts of one and two have to
| be able to answer.
|
| Shibboleth has already authenticated the uid by the time a request arrives,
| so an allowlist is the whole access check.
|
*/

if (! function_exists('study_require_admin')) {
    function study_require_admin(Request $request): void
    {
        $uid = $request->student->uid ?? null;

        if (! $uid || ! in_array($uid, config('study.admin_uids'), true)) {
            abort(403, 'not a study administrator');
        }
    }
}

Route::get('/study/monitor', function (Request $request) {

    study_require_admin($request);

    $phase = (string) config('study.phase');

    $slots = DB::table('allocation_slot')
        ->where('phase', $phase)
        ->orderBy('stratum')
        ->orderBy('seq')
        ->get();

    //students.arm is written by the same transaction that claims the slot, so
    //the two must agree. Surfacing the comparison rather than assuming it is
    //what makes this a check instead of a display.
    $armByUid = DB::table('students')
        ->whereNotNull('arm')
        ->pluck('arm', 'uid');

    //columns come from the configured arms, so every arm shows even at 0; an
    //arm in the slots but not in config (a stale seed) still gets counted
    $arms = array_keys(config('study.arms'));

    $buckets = [];
    $ledger = [];
    $remaining = [];

    foreach ($slots as $slot) {
        $buckets[$slot->stratum] ??= array_fill_keys($arms, 0);
        $remaining[$slot->stratum] ??= 0;

        if ($slot->claimed_uid === null) {
            $remaining[$slot->stratum]++;

            continue;
        }

        $buckets[$slot->stratum][$slot->arm] =
            ($buckets[$slot->stratum][$slot->arm] ?? 0) + 1;

        //An arm that is not configured agrees with itself, so the comparison
        //alone would call it 'ok' - while /messages quietly answers that student
        //as control (e.g. the two-arm pilot's 'rag'). It is not ok.
        $knownArm = StudyArm::known($slot->arm);

        $ledger[] = [
            'seq' => $slot->seq,
            'stratum' => $slot->stratum,
            'arm' => $slot->arm,
            'uid' => $slot->claimed_uid,
            'claimed_at' => $slot->claimed_at,
            'student_arm' => $armByUid[$slot->claimed_uid] ?? null,
            'known_arm' => $knownArm,
            'consistent' => $knownArm && ($armByUid[$slot->claimed_uid] ?? null) === $slot->arm,
        ];
    }

    usort($ledger, fn ($a, $b) => strcmp((string) $a['claimed_at'], (string) $b['claimed_at']));

    //which corpus the RAG arms are answering from, and whether it loads at all:
    //KbRetriever fails soft, so a broken index would otherwise only show up as
    //both RAG arms quietly answering like no-RAG
    $index = app(KbRetriever::class)->status();

    $payload = [
        'phase' => $phase,
        'rag_enabled' => (bool) config('tutor.rag_enabled'),
        'arm_override' => (bool) config('study.arm_override'),
        'index' => $index,
        'buckets' => $buckets,
        'slots_remaining' => $remaining,
        'ledger' => $ledger,
    ];

    if ($request->query('format') !== 'html') {
        return response()->json($payload);
    }

    $rows = '';
    foreach ($ledger as $r) {
        $flag = $r['consistent'] ? '' : ' style="background:#fdd"';
        $rows .= sprintf(
            '<tr%s><td>%d</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
            $flag,
            $r['seq'],
            e($r['stratum']),
            e($r['arm']),
            e($r['uid']),
            e((string) $r['claimed_at']),
            ! $r['known_arm'] ? 'UNKNOWN ARM' : ($r['consistent'] ? 'ok' : 'MISMATCH')
        );
    }

    //configured arms first, then any stale arm the slots still carry - a claim
    //under an unconfigured arm must show up here, not vanish from the counts
    $columns = $arms;
    foreach ($buckets as $byArm) {
        foreach (array_keys($byArm) as $arm) {
            if (! in_array($arm, $columns, true)) {
                $columns[] = $arm;
            }
        }
    }

    $counts = '';
    foreach ($buckets as $stratum => $byArm) {
        $cells = '';
        foreach ($columns as $arm) {
            $cells .= '<td>'.(int) ($byArm[$arm] ?? 0).'</td>';
        }
        $counts .= sprintf(
            '<tr><td>%s</td>%s<td>%d</td></tr>',
            e($stratum),
            $cells,
            $remaining[$stratum] ?? 0
        );
    }
    $armHeaders = implode('', array_map(
        fn ($a) => '<th>'.e($a).(StudyArm::known($a) ? '' : ' (unknown)').'</th>',
        $columns
    ));

    return response(
        '<!doctype html><meta charset="utf-8"><title>StatsBot allocation</title>'
        .'<style>body{font:14px system-ui;margin:2rem}table{border-collapse:collapse;margin-bottom:2rem}'
        .'td,th{border:1px solid #ccc;padding:.35rem .7rem;text-align:left}</style>'
        .'<h1>Allocation monitor</h1>'
        .'<p>phase: <b>'.e($phase).'</b> &middot; RAG_ENABLED: <b>'
        .(config('tutor.rag_enabled') ? 'true' : 'false').'</b> &middot; arm override: <b>'
        .(config('study.arm_override') ? 'ON' : 'off').'</b></p>'
        .'<p>index: '.($index['ok']
            ? '<b>'.e((string) $index['version']).'</b> &middot; '.(int) $index['chunks'].' chunks ('
                .(int) $index['with_citations'].' with citations) &middot; sha256 '.e(substr((string) $index['sha256'], 0, 12))
            : '<b style="color:#b00">NOT LOADABLE</b> &middot; '.e((string) $index['error'])).'</p>'
        .'<h2>Buckets</h2><table><tr><th>stratum</th>'.$armHeaders.'<th>slots left</th></tr>'
        .$counts.'</table>'
        .'<h2>Claim ledger</h2><table><tr><th>seq</th><th>stratum</th><th>arm</th><th>uid</th>'
        .'<th>claimed at</th><th>students.arm</th></tr>'.$rows.'</table>',
        200,
        ['Content-Type' => 'text/html; charset=utf-8']
    );
});

Route::post('/study/reset', function (Request $request) {

    study_require_admin($request);

    //A reset during the real study would silently re-randomise a live
    //participant - the one failure in this system that invalidates data without
    //producing an error. Hard refusal, not a warning.
    if (config('study.phase') !== 'test') {
        abort(403, 'reset is only available in the test phase');
    }

    //same release `study:seed-test --force` performs - see Allocator
    try {
        $uids = Allocator::releaseTestPhase();
    } catch (\RuntimeException $e) {
        abort(409, $e->getMessage());
    }

    return response()->json(['released' => count($uids), 'uids' => $uids]);
});
