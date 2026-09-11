<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use Illuminate\Support\Facades\Http;

use App\Models\Student;
use App\Models\History;

use Illuminate\Support\Facades\DB;

use App\Services\KbRetriever;
use App\Services\TutorPrompt;

Route::get('/student', function(Request $request){

    return $request->student;

});

Route::post('/register', function(Request $request){

    $existingStudent = Student::where("uid", $request->student->uid)->first();
    if(!empty($existingStudent)){

        if(!$existingStudent->registered){

            $existingStudent->registered = true;
            $existingStudent->save();

        }

        return $existingStudent;
    }
    else{

    $student = new Student();
    $student->uid = $request->student->uid;
    $student->firstname = $request->student->firstname;
    $student->lastname = $request->student->lastname;
    $student->token_limit=env('TOKEN_LIMIT', 0);
    $student->token_left=env('TOKEN_LIMIT', 0);

    $student->activated=true;
    $student->registered=true;

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
Route::get('/history', function(Request $request){

    $firstOfEachThread = DB::table('history')
        ->selectRaw('MIN(id)')
        ->where('student_id', $request->student->id)
        ->groupBy('started');

    return DB::table('history')
        ->selectRaw('id, SUBSTRING(sent, 1, 200) as sent, started, created_at')
        ->whereIn('id', $firstOfEachThread)
        ->get();
});

Route::get('/history/{started}', function(Request $request, $started){

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
        'started' => 'required|numeric'
    ]);

    $messages = $request->messages;
    $student = $request->student;
    $started = $request->started;
    unset($student->registered);

    if(!$student){
        abort(403, 'no student');
    }

    if(!$student->activated){
        abort(403, 'not activated');
    }

    if($student->token_left <= 0){
        abort(403, 'no tokens left');
    }


    //grounding: retrieve passages from the course materials for THIS turn.
    //RAG_ENABLED=false skips retrieval entirely and restores the ungrounded
    //behaviour without moving a file - the pilot's rollback path on the pod.
    //search() returns [] on any failure, so a broken index degrades the tutor
    //rather than taking it offline.
    $retriever = app(KbRetriever::class);
    $hits = config('tutor.rag_enabled')
        ? $retriever->search(end($messages)['content'] ?? '')
        : [];

    $payload = [
        'model' => env('AZURE_MODEL', 'no_endpoint_available'),
        //system prompt first (stable prefix, so prompt caching survives),
        //retrieved passages last, as their own message - never appended to the
        //student's text, which is persisted verbatim to history.sent below.
        'messages' => TutorPrompt::assemble($messages, $hits),
    ];

    //the two answer layers are separated by parsing JSON, not by prose headings
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
        'api-key' => env('AZURE_API_KEY', 'no_key_available')
        ])->post(env('AZURE_ENDPOINT', 'no_endpoint_available')."/openai/deployments/".env('AZURE_DEPLOYMENT', 'no_deployment_available')."/chat/completions?api-version=".env('AZURE_API_VERSION', 'no_api_version_available'), $payload);

    $answer = TutorPrompt::parse($gptResponse["choices"][0]["message"]["content"] ?? null, $hits);
    $responseMessage = $answer['content'];

    //NOTE: $messages here is still the student's own conversation - assemble()
    //worked on a copy - so history.sent stays exactly what the student typed.
    $lastSentMessage = end($messages);

    $history = new History();
    $history->student_id = $request->student->id;
    $history->sent = $lastSentMessage["content"];
    $history->started = $started;
    $history->received = $responseMessage;
    $history->prompt_tokens = $gptResponse["usage"]["prompt_tokens"];
    $history->completion_tokens = $gptResponse["usage"]["completion_tokens"];
    $history->total_tokens = $gptResponse["usage"]["total_tokens"];

    //which corpus answered this turn - see the add_retrieval_provenance migration.
    //Rows before and after grounding goes live are otherwise indistinguishable,
    //and that cannot be reconstructed after the fact.
    $history->kb_version = config('tutor.rag_enabled') ? $retriever->version() : null;
    $history->kb_chunks = $hits ? json_encode(array_map(
        fn ($h) => ['id' => $h['id'], 'score' => $h['score']], $hits
    )) : null;
    $history->grounded = $answer['from_materials'] !== null;


    $history->save();

    $student->token_left = $student->token_left - $history->total_tokens;

    $student->save();

    return response()->json([
        //'content' keeps the flattened two-layer text so an un-updated client
        //still renders a complete answer; the split fields are additive.
        'content' =>  $history->received,
        'from_materials' => $answer['from_materials'],
        'from_general' => $answer['from_general'],
        'sources' => $answer['sources'],
        'grounded' => $answer['from_materials'] !== null,
        //sent rather than duplicated in the client, so the wording has one home
        'materials_note' => $answer['from_materials'] === null
            ? config('tutor.no_material_note')
            : null,
        'token_left' => $student->token_left,
        'costs' =>  $history->total_tokens
    ]);

});
