<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Reconciled 2026-09-02 against the live pod schema. Only the foreign key
     * differed; columns and types already matched.
     */
    public function up(): void
    {
        Schema::create('history', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('student_id')->unsigned();
            $table->text('sent');
            $table->text('received');
            $table->integer('prompt_tokens');
            $table->integer('completion_tokens');
            $table->integer('total_tokens');
            $table->bigInteger('started')->unsigned();
            $table->timestamps();
            //live schema has ON DELETE CASCADE (verified 2026-09-02); the repo
            //copy omitted it, so a fresh database behaved differently to production
            $table->foreign('student_id')->references('id')->on('students')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('history');
    }
};
