<?php

namespace Tests\Feature;

use App\Models\History;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Regression test for #4.
 *
 * Laravel applies DB_PREFIX in the query GRAMMAR, so the builder and Eloquent
 * honour it and a raw SQL string does not. /history used raw DB::select, so on
 * the prefixed rag-pilot stack writes went to rag_history via Eloquent while
 * reads went to the unprefixed live `history` - an empty list for the student,
 * and a student_id that could match an unrelated live row.
 *
 * The default test connection sets no prefix, which is exactly why the original
 * suite could not catch this. This case sets one, migrates a second prefixed
 * set of tables, and puts a decoy row in the unprefixed table - the shape of the
 * production pod.
 */
class HistoryPrefixTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // unprefixed tables now exist (RefreshDatabase migrated them); stand up
        // the prefixed set alongside, the way the pilot stack is deployed.
        //
        // The migration files are invoked directly rather than through `artisan
        // migrate`: on the :memory: connection the migrator would also want a
        // prefixed `migrations` table, and purging the connection to rebuild it
        // would discard the unprefixed tables this test needs as the decoy.
        // setTablePrefix() updates the QUERY grammar only; the schema grammar
        // keeps the prefix it was built with, so Schema::create would still
        // emit unprefixed DDL without this rebuild.
        DB::connection()->setTablePrefix('rag_');
        DB::connection()->useDefaultSchemaGrammar();

        foreach ([
            '2024_07_12_170459_create_students_table.php',
            '2024_07_12_171027_create_history_table.php',
            '2026_09_02_120000_add_retrieval_provenance_to_history_table.php',
            '2026_09_11_100200_add_arm_to_students_table.php',
            '2026_09_11_100300_add_arm_to_history_table.php',
        ] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }

        $_SERVER['uid'] = 'u:test01';
        $_SERVER['givenName'] = 'Test';
        $_SERVER['sn'] = 'Student';
    }

    protected function tearDown(): void
    {
        DB::connection()->setTablePrefix('');
        DB::connection()->useDefaultSchemaGrammar();
        unset($_SERVER['uid'], $_SERVER['givenName'], $_SERVER['sn']);
        parent::tearDown();
    }

    public function test_history_reads_the_prefixed_table_the_writes_went_to(): void
    {
        $student = new Student;
        $student->uid = 'u:test01';
        $student->firstname = 'Test';
        $student->lastname = 'Student';
        $student->token_limit = 1000;
        $student->token_left = 1000;
        $student->activated = true;
        $student->registered = true;
        $student->save();

        $row = new History;
        $row->student_id = $student->id;
        $row->sent = 'pilot question';
        $row->received = 'pilot answer';
        $row->prompt_tokens = 1;
        $row->completion_tokens = 1;
        $row->total_tokens = 2;
        $row->started = 1756800000000;
        $row->save();

        // a decoy in the UNPREFIXED table, sharing the student_id. Raw SQL
        // bypasses the prefix - which is the bug, used here to build the fixture.
        DB::statement("INSERT INTO students (id, uid, firstname, lastname, token_limit, token_left, registered, activated, created_at, updated_at)
                       VALUES (?, 'u:someone-else', 'Someone', 'Else', 1, 1, 1, 1, ?, ?)",
            [$student->id, now(), now()]);
        DB::statement("INSERT INTO history (student_id, sent, received, prompt_tokens, completion_tokens, total_tokens, started, created_at, updated_at)
                       VALUES (?, 'ANOTHER STUDENTS PRIVATE CHAT', 'x', 1, 1, 2, ?, ?, ?)",
            [$student->id, 1756800000001, now(), now()]);

        $response = $this->getJson('/api/history')->assertOk();

        $this->assertCount(1, $response->json(),
            'reads must not cross the prefix boundary into the live table');
        $this->assertSame('pilot question', $response->json('0.sent'));

        $thread = $this->getJson('/api/history/1756800000000')->assertOk();
        $this->assertCount(1, $thread->json());
        $this->assertSame('pilot answer', $thread->json('0.received'));
    }
}
