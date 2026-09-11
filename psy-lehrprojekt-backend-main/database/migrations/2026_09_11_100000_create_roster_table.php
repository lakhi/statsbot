<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Program-level roster: the stratum lookup for allocation.
     *
     * Degree level is NOT derivable from a u:account ID, so it has to be
     * imported from a roster list handed over before the study opens. This
     * mirrors StatsBotEval's `student_status` table (pipeline/migrations/
     * 004_student_status.sql), which resolves the same fact for analysis, so
     * the real roster import drops into both with one shape.
     *
     * A uid that is absent here is allocated from the `unknown` stratum and
     * flagged for exclusion from the primary analysis - it is never turned away.
     */
    public function up(): void
    {
        Schema::create('roster', function (Blueprint $table) {
            $table->string('uid')->primary();

            //'bachelor' | 'master'. Deliberately a string rather than an enum:
            //adding a level later must not require a migration mid-study.
            $table->string('status');

            //source roster list, e.g. 'master-mar25' - kept so a mis-stratified
            //student can be traced back to the list that said so.
            $table->string('provenance');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roster');
    }
};
