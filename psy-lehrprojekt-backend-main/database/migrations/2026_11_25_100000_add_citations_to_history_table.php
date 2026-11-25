<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What an answer cited, and what the student was shown - two different
     * things once there are three arms.
     *
     * The salient and non-salient RAG arms get the same retrieval, the same
     * prompt and the same answer; only the display differs. So the model's
     * citations have to be recorded for BOTH, or grounding and misattribution
     * cannot be measured for the non-salient arm at all. And what was displayed
     * has to be stamped rather than recomputed from `cited`, for the same reason
     * history.arm is stamped: the cap and dedupe rule could change between
     * cohorts, and a recomputation would silently rewrite what a student saw.
     *
     * Manipulation checks in the preregistration read straight off these:
     *   (b) share of salient rows whose sources_shown is non-empty;
     *   misattribution: cited ids vs kb_chunks.
     *
     * Applied automatically on the /rag-pilot-test stack (rag_ prefix); applying
     * it to the live database is a separate, deliberate step.
     */
    public function up(): void
    {
        Schema::table('history', function (Blueprint $table) {
            //every valid citation the model made, uncapped, as JSON
            //[{id, label, score}]. NULL for arms that do not retrieve; [] when a
            //RAG answer cited nothing.
            $table->text('cited')->nullable()->after('kb_chunks');

            //exactly what was displayed, as JSON [{id, label}]. NULL for arms
            //that show no sources; [] when the salient arm showed its "no
            //matching course material" note instead of chips.
            $table->text('sources_shown')->nullable()->after('cited');
        });
    }

    public function down(): void
    {
        Schema::table('history', function (Blueprint $table) {
            $table->dropColumn(['cited', 'sources_shown']);
        });
    }
};
