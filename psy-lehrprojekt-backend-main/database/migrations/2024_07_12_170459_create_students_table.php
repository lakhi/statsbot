<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('students', function (Blueprint $table) {
            $table->collation('utf8mb4_unicode_ci');
            $table->id();
            $table->string('uid');
            $table->string('firstname');
            $table->string('lastname');
            $table->string('matnr');
            $table->integer('token_limit');
            $table->integer('token_left');
            $table->string('lv');
            $table->boolean('activated');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('students');
    }
};
