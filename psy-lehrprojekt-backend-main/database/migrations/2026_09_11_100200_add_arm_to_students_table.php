<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The student's trial arm, bound once when they accept the disclaimer.
     *
     * NULL means "not allocated": either the student registered before the
     * study opened, or the study is not running (STUDY_PHASE=off). An arm is
     * never reassigned - /register refuses to claim a second slot for a student
     * that already has one, so a re-submitted disclaimer cannot re-randomise a
     * participant.
     */
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            //'rag' | 'no_rag', null until allocated
            $table->string('arm')->nullable()->after('registered');
            $table->timestamp('allocated_at')->nullable()->after('arm');
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropColumn(['arm', 'allocated_at']);
        });
    }
};
