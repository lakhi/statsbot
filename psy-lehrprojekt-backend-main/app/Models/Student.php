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
}
