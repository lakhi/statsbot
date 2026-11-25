<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Student extends Model
{
    use HasFactory;

    //registered and activated are tinyint(1). Without these casts the PHP type
    //PDO hands back depends on driver fetch typing, and the two languages
    //disagree about the result: "0" is FALSY in PHP but TRUTHY in JavaScript.
    //The frontend decides whether to show the disclaimer with
    //!student().registered, so a stringified 0 would silently stop showing it
    //while every backend check still behaved correctly. Casting pins the JSON
    //to a real boolean.
    protected $casts = [
        'registered' => 'boolean',
        'activated' => 'boolean',
    ];

    //Never serialised to the browser. /student and /register return this model
    //as JSON, so without this every student could read their own trial arm in
    //the network tab. The frontend needs no arm: what to render follows from the
    //shape of each /messages response. Writes are unaffected.
    protected $hidden = [
        'arm',
        'allocated_at',
    ];
}
