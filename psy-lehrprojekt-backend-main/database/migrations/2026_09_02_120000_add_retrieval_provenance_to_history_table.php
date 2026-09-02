<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Records WHICH corpus answered each turn.
     *
     * Without this, rows written before and after grounding goes live are
     * indistinguishable: the thesis corpus would contain two different tutors
     * with nothing in the data saying which is which. It cannot be reconstructed
     * afterwards, so it has to exist before the first grounded answer reaches a
     * student.
     *
     * All columns are nullable, so ungrounded turns (RAG_ENABLED=false, or a
     * retrieval outage) simply leave them null and remain valid rows.
     *
     * NOTE: the pilot backend WRITES these columns, so this migration must be
     * applied to any database that backend talks to. On the /rag-pilot-test stack
     * that is automatic - it runs migrations from scratch against rag_-prefixed
     * tables. Applying it to the live database is a separate, deliberate step.
     */
    public function up(): void
    {
        Schema::table('history', function (Blueprint $table) {
            //index identity, e.g. "hyptest@2026-09-02T14:31:00+00:00"
            $table->string('kb_version')->nullable()->after('total_tokens');

            //ids + scores of the passages injected, as JSON; null when none were
            $table->text('kb_chunks')->nullable()->after('kb_version');

            //did the answer carry a course-materials layer?
            $table->boolean('grounded')->nullable()->after('kb_chunks');
        });
    }

    public function down(): void
    {
        Schema::table('history', function (Blueprint $table) {
            $table->dropColumn(['kb_version', 'kb_chunks', 'grounded']);
        });
    }
};
