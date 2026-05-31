<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use Illuminate\Support\Facades\Http;

use App\Models\Student;
use App\Models\History;

//get stundent data merged in middleware
Route::get('/student', function(Request $request){

    return $request->student;

});

//register new student
Route::post('/register', function(Request $request){

    //check if student already exists in database
    $existingStudent = Student::where("uid", $request->student->uid)->first();
    if(!empty($existingStudent)){ return $existingStudent;}

    else{

    //create new student and save object    
    $student = new Student();
    $student->uid = $request->student->uid;
    $student->firstname = $request->student->firstname;
    $student->lastname = $request->student->lastname;
    
    //set token limits from .env file
    $student->token_limit=env('TOKEN_LIMIT', 0);
    $student->token_left=env('TOKEN_LIMIT', 0);
    
    //auto activate new student
    $student->activated=true;
    
    $student->save();

    $student->registered=true;
    
    return $student; 

    }

});

//get history list of chats 
Route::get('/history', function(Request $request){

    return DB::select("SELECT id, SUBSTRING(sent, 1, 200) as sent, started, created_at FROM history WHERE id IN (SELECT min(id) FROM history WHERE student_id = ? GROUP BY started)",[$request->student->id]);
});

//get messages of a specific chat
Route::get('/history/{started}', function(Request $request, $started){

    return DB::select("SELECT * FROM history WHERE student_id = ? AND started = ? ORDER BY id", [$request->student->id, $started]);

});

//Send message to GPT model and save messages
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

    //get answer from GPT model
    $gptResponse = Http::withHeaders([
        'Content-Type' => 'application/json',
        'api-key' => env('AZURE_API_KEY', 'no_key_available') 
        ])->post(env('AZURE_ENDPOINT', 'no_endpoint_available')."/openai/deployments/".env('AZURE_DEPLOYMENT', 'no_deployment_available')."/chat/completions?api-version=".env('AZURE_API_VERSION', 'no_api_version_available'), [
            'model' => env('AZURE_MODEL', 'no_endpoint_available'),
            'messages' => $messages, 
            'temperature' => 0.7
        ]);
       

    $responseMessage = $gptResponse["choices"][0]["message"]["content"];

    $lastSentMessage = end($messages);

    //create history record in database
    $history = new History();
    $history->student_id = $request->student->id;
    $history->sent = $lastSentMessage["content"];
    $history->started = $started;
    $history->received = $responseMessage;
    $history->prompt_tokens = $gptResponse["usage"]["prompt_tokens"];
    $history->completion_tokens = $gptResponse["usage"]["completion_tokens"];
    $history->total_tokens = $gptResponse["usage"]["total_tokens"];

    $history->save();

    //calculate token left
    $student->token_left = $student->token_left - $history->total_tokens;

    $student->save(); 

    return response()->json([
        'content' =>  $history->received,
        'token_left' => $student->token_left,
        'costs' =>  $history->total_tokens
    ]);

});
