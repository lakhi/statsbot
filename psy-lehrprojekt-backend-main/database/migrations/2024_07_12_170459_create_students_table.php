<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * RECONCILED 2026-09-02 against the live pod schema (SHOW CREATE TABLE students
     * on lehrprojeg67, MariaDB 11.4.13). This migration previously did NOT describe
     * production, in both directions:
     *
     *   - it was MISSING `registered`, which api.php and AuthenticateStudent both
     *     write, and which exists live as tinyint(1) NOT NULL DEFAULT 0;
     *   - it declared `matnr` and `lv` as NOT NULL strings with no default, and
     *     NEITHER COLUMN EXISTS in the live table. /register never set them, so a
     *     fresh `artisan migrate` produced a table that /register could not insert
     *     into under strict mode.
     *
     * Student roster data (Matrikelnummer, groups) lives in the separate `import`
     * table, keyed by uid - not on `students`.
     *
     * Editing a shipped migration is normally wrong. It is right here because this
     * one never matched the database it claims to create: production was built by
     * another path, and the repo copy has been unrunnable since. See issue #1.
     */
    public function up(): void
    {
        Schema::create('students', function (Blueprint $table) {
            $table->collation('utf8mb4_unicode_ci');
            $table->id();
            $table->string('uid');
            $table->string('firstname');
            $table->string('lastname');
            $table->integer('token_limit');
            $table->integer('token_left');
            $table->boolean('registered')->default(false);
            $table->boolean('activated');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('students');
    }
};
