<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

use App\Models\Student;

class StudentSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Student::create(
            ["firstname" => "Max", "lastname" => "Steiner", "uid" => "steinew9", "matnr" => "09826269", "token_limit" => 1000, "token_left" => 500, "lv" => "Test LV", "activated" => true]
        );
    }
}
