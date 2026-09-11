<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which arm answered THIS turn, stamped on the row at write time.
     *
     * This is the same argument the add_retrieval_provenance migration makes,
     * one column wider. The arm also lives on `students`, so it is tempting to
     * recover it in analysis by joining history -> students. That join is wrong:
     * it labels every message a student sent BEFORE the study - answered by an
     * ungrounded tutor, under a different prompt - with the arm they were later
     * allocated to. It would not error. It would silently file pre-study data
     * under the experimental condition.
     *
     * Stamped here, a pre-study row keeps arm = NULL and is unambiguously
     * outside the trial. Like the provenance columns, this cannot be
     * reconstructed afterwards, so it has to exist before the first allocated
     * student sends a message.
     */
    public function up(): void
    {
        Schema::table('history', function (Blueprint $table) {
            $table->string('arm')->nullable()->after('grounded');
        });
    }

    public function down(): void
    {
        Schema::table('history', function (Blueprint $table) {
            $table->dropColumn('arm');
        });
    }
};
