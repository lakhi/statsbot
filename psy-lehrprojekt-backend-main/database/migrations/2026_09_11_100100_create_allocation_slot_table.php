<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The randomisation list: one row per allocation, arms fixed in advance.
     *
     * WHY A PRE-SEEDED TABLE AND NOT A COIN FLIP AT RUNTIME.
     *
     * Hashing a uid into two arms is stateless and sticky, but it cannot
     * stratify: within bachelor and within master the split is binomial, so at
     * n~40 per stratum a 26/14 imbalance is an ordinary outcome. Permuted blocks
     * hold the arms within +/-(block/2) of each other at EVERY point during
     * recruitment, which also protects the study if it is cut short.
     *
     * The randomness lives offline - the sequence is generated with a recorded
     * seed and imported here before the first student arrives. Production only
     * ever claims the next unclaimed row, so there is no RNG in the request path
     * and the whole allocation is auditable after the fact.
     *
     * Rows are claimed at the moment a student accepts the disclaimer, not when
     * the roster is imported: randomising the invitation list balances the
     * roster, and a random subset of a balanced list is only binomially
     * balanced. Randomising at enrolment is what balances the students who
     * actually turn up.
     */
    public function up(): void
    {
        Schema::create('allocation_slot', function (Blueprint $table) {
            $table->id();

            //'test' rehearses the mechanism; 'study' is the real sequence. Kept
            //apart so a rehearsal can never consume a study allocation, and so
            //the test ledger survives as evidence instead of being truncated.
            $table->string('phase');

            //'bachelor' | 'master' | 'unknown' - one independent sequence each.
            $table->string('stratum');

            //position within this phase+stratum sequence, 1-based.
            $table->unsignedInteger('seq');

            //'rag' | 'no_rag'. Pre-seeded and never written again.
            $table->string('arm');

            $table->string('claimed_uid')->nullable();
            $table->timestamp('claimed_at')->nullable();

            $table->timestamps();

            //the sequence must have exactly one row per position
            $table->unique(['phase', 'stratum', 'seq']);

            //covers the claim query: next unclaimed row in this phase+stratum
            $table->index(['phase', 'stratum', 'claimed_uid', 'seq']);

            //one student can hold at most one slot, enforced by the database
            //rather than by the claim being careful
            $table->unique(['phase', 'claimed_uid']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('allocation_slot');
    }
};
