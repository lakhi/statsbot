<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

use App\Models\Student;

class AuthenticateStudent
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        
        //check if user is authenticated via shibboleth => uid available
        if(empty($_SERVER['uid'])){ abort(403, 'Access denied'); }
        
        //set u:ccount ID 
        $uid = $_SERVER['uid'];
        
        //get student with u:account ID from database
        $currentStudent = Student::where('uid', $uid)->first();
     
        //create new student in database
        if(empty($currentStudent)){

            $student = new Student();
            $student->registered=false;
            
            //set student data from shibboleth auth
            $student->uid = $_SERVER['uid'];
            $student->firstname = $_SERVER['givenName'];
            $student->lastname = $_SERVER['sn'];

            //$student->uid = $uid;
            //$student->firstname = "Max99";
            //$student->lastname = "Mustermann99";
        
            //add new student to request
            $request->merge(['student' => $student]);
        }
        //add existing student to request
        else{
            $currentStudent->registered=true;
            $request->merge(['student' => $currentStudent]);
        }
       
       

        return $next($request);
    }
}
