<?php

use App\Models\History;
use App\Models\Student;
use App\Services\Allocator;
use App\Services\KbRetriever;
use App\Services\TutorPrompt;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

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

    return DB::table('history')
        ->where('student_id', $request->student->id)
        ->where('started', $started)
        ->orderBy('id')
        ->get();

});

Route::post('/messages', function (Request $request) {

    $validated = $request->validate([
        'messages.*.content' => 'required|string',
        'messages.*.role' => 'required|in:assistant,user',
        'started' => 'required|numeric',
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
    //search() returns [] on any failure, so a broken index degrades the tutor
    //rather than taking it offline.
    $grounded = config('tutor.rag_enabled') && $student->arm === 'rag';

    $retriever = app(KbRetriever::class);
    $hits = $grounded
        ? $retriever->search(end($messages)['content'] ?? '')
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
    $responseMessage = $answer['content'];

    //NOTE: $messages here is still the student's own conversation - assemble()
    //worked on a copy - so history.sent stays exactly what the student typed.
    $lastSentMessage = end($messages);

    $history = new History();
    $history->student_id = $request->student->id;
    $history->sent = $lastSentMessage['content'];
    $history->started = $started;
    $history->received = $responseMessage;
    $history->prompt_tokens = $gptResponse['usage']['prompt_tokens'];
    $history->completion_tokens = $gptResponse['usage']['completion_tokens'];
    $history->total_tokens = $gptResponse['usage']['total_tokens'];

    //which corpus answered this turn - see the add_retrieval_provenance migration.
    //Rows before and after grounding goes live are otherwise indistinguishable,
    //and that cannot be reconstructed after the fact.
    $history->kb_version = $grounded ? $retriever->version() : null;
    $history->kb_chunks = $hits ? json_encode(array_map(
        fn ($h) => ['id' => $h['id'], 'score' => $h['score']], $hits
    )) : null;
    $history->grounded = $answer['sources'] !== [];

    //Stamped, never joined from students.arm - see the add_arm_to_history
    //migration. A join would label this student's PRE-study messages with the
    //arm they were later allocated to.
    $history->arm = $student->arm;

    $history->save();

    $student->token_left = $student->token_left - $history->total_tokens;

    $student->save();

    return response()->json([
        'content' => $history->received,
        'sources' => $answer['sources'],
        'grounded' => $history->grounded,
        'token_left' => $student->token_left,
        'costs' => $history->total_tokens,
    ]);

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

    $buckets = [];
    $ledger = [];
    $remaining = [];

    foreach ($slots as $slot) {
        $buckets[$slot->stratum]['rag'] ??= 0;
        $buckets[$slot->stratum]['no_rag'] ??= 0;
        $remaining[$slot->stratum] ??= 0;

        if ($slot->claimed_uid === null) {
            $remaining[$slot->stratum]++;

            continue;
        }

        $buckets[$slot->stratum][$slot->arm] =
            ($buckets[$slot->stratum][$slot->arm] ?? 0) + 1;

        $ledger[] = [
            'seq' => $slot->seq,
            'stratum' => $slot->stratum,
            'arm' => $slot->arm,
            'uid' => $slot->claimed_uid,
            'claimed_at' => $slot->claimed_at,
            'student_arm' => $armByUid[$slot->claimed_uid] ?? null,
            'consistent' => ($armByUid[$slot->claimed_uid] ?? null) === $slot->arm,
        ];
    }

    usort($ledger, fn ($a, $b) => strcmp((string) $a['claimed_at'], (string) $b['claimed_at']));

    $payload = [
        'phase' => $phase,
        'rag_enabled' => (bool) config('tutor.rag_enabled'),
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
            $r['consistent'] ? 'ok' : 'MISMATCH'
        );
    }

    $counts = '';
    foreach ($buckets as $stratum => $arms) {
        $counts .= sprintf(
            '<tr><td>%s</td><td>%d</td><td>%d</td><td>%d</td></tr>',
            e($stratum),
            $arms['rag'] ?? 0,
            $arms['no_rag'] ?? 0,
            $remaining[$stratum] ?? 0
        );
    }

    return response(
        '<!doctype html><meta charset="utf-8"><title>StatsBot allocation</title>'
        .'<style>body{font:14px system-ui;margin:2rem}table{border-collapse:collapse;margin-bottom:2rem}'
        .'td,th{border:1px solid #ccc;padding:.35rem .7rem;text-align:left}</style>'
        .'<h1>Allocation monitor</h1>'
        .'<p>phase: <b>'.e($phase).'</b> &middot; RAG_ENABLED: <b>'
        .(config('tutor.rag_enabled') ? 'true' : 'false').'</b></p>'
        .'<h2>Buckets</h2><table><tr><th>stratum</th><th>rag</th><th>no_rag</th><th>slots left</th></tr>'
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

    $uids = DB::table('allocation_slot')
        ->where('phase', 'test')
        ->whereNotNull('claimed_uid')
        ->pluck('claimed_uid')
        ->all();

    DB::transaction(function () use ($uids) {
        DB::table('allocation_slot')
            ->where('phase', 'test')
            ->update(['claimed_uid' => null, 'claimed_at' => null, 'updated_at' => now()]);

        if ($uids !== []) {
            //registered = 0 is what sends them back through the disclaimer,
            //which is what triggers the next claim. history rows are left alone
            //on purpose: arm is stamped per row, so earlier runs stay readable.
            DB::table('students')
                ->whereIn('uid', $uids)
                ->update([
                    'arm' => null,
                    'allocated_at' => null,
                    'registered' => 0,
                    'token_left' => DB::raw('token_limit'),
                    'updated_at' => now(),
                ]);
        }
    });

    return response()->json(['released' => count($uids), 'uids' => $uids]);
});
