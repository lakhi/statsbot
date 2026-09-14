<?php

namespace Tests\Feature;

use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * students.registered has to survive a round trip through the API.
 *
 * AuthenticateStudent used to force it to true in memory for every student
 * whose row existed, which made the column write-only: /student always reported
 * a registered student, so the disclaimer could never be shown again, and
 * /register - the only place a trial arm is allocated - became unreachable for
 * anyone already in the table. See #6.
 *
 * That is why these assertions look almost too obvious to write. The bug was
 * not a wrong value, it was a value nobody could observe.
 */
class RegistrationStateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // AuthenticateStudent reads Shibboleth attributes off $_SERVER.
        $_SERVER['uid'] = 'u:test01';
        $_SERVER['givenName'] = 'Test';
        $_SERVER['sn'] = 'Student';
    }

    protected function tearDown(): void
    {
        unset($_SERVER['uid'], $_SERVER['givenName'], $_SERVER['sn']);
        parent::tearDown();
    }

    private function seedStudent(bool $registered): Student
    {
        $s = new Student;
        $s->uid = 'u:test01';
        $s->firstname = 'Test';
        $s->lastname = 'Student';
        $s->token_limit = 10000;
        $s->token_left = 10000;
        $s->activated = true;
        $s->registered = $registered;
        $s->save();

        return $s;
    }

    public function test_an_existing_student_reports_the_stored_registration_state(): void
    {
        $this->seedStudent(false);

        $this->getJson('/api/student')
            ->assertOk()
            ->assertJsonPath('registered', false);
    }

    public function test_a_registered_student_still_reports_registered(): void
    {
        $this->seedStudent(true);

        $this->getJson('/api/student')
            ->assertOk()
            ->assertJsonPath('registered', true);
    }

    public function test_registered_is_a_json_boolean_not_a_number_or_string(): void
    {
        // The frontend gates the disclaimer on !student().registered, and the
        // two languages disagree about "0": falsy in PHP, TRUTHY in JavaScript.
        // A stringified flag would silently stop showing the disclaimer while
        // every backend check kept behaving correctly.
        $this->seedStudent(false);

        $value = $this->getJson('/api/student')->json('registered');

        $this->assertIsBool($value, 'registered must serialise as a JSON boolean');
        $this->assertFalse($value);
    }

    public function test_a_message_turn_does_not_re_register_the_student(): void
    {
        $this->seedStudent(false);

        Http::fake(['*/chat/completions*' => Http::response([
            'choices' => [['message' => ['content' => json_encode([
                'answer' => 'an answer', 'citations' => [],
            ])]]],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
        ])]);

        $this->postJson('/api/messages', [
            'messages' => [['role' => 'user', 'content' => 'q']],
            'started' => 1756800000000,
        ])->assertOk();

        // /messages saves the student to decrement tokens. registered must not
        // ride along on that save - api.php unsets it first.
        $this->assertSame(0, (int) DB::table('students')->where('uid', 'u:test01')->value('registered'));
    }

    public function test_an_existing_unregistered_student_registers_and_is_allocated(): void
    {
        // The path the study depends on: an existing row reset to registered=0
        // must be able to reach /register and claim a slot.
        config(['study.phase' => 'test']);
        $this->seedStudent(false);

        DB::table('roster')->insert([
            'uid' => 'u:test01', 'status' => 'bachelor', 'provenance' => 'test',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('allocation_slot')->insert([
            'phase' => 'test', 'stratum' => 'bachelor', 'seq' => 1, 'arm' => 'rag',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->postJson('/api/register', [])
            ->assertSuccessful()
            ->assertJsonPath('arm', 'rag')
            ->assertJsonPath('registered', true);

        $this->assertSame('u:test01', DB::table('allocation_slot')->where('seq', 1)->value('claimed_uid'));
        $this->assertSame(1, (int) DB::table('students')->where('uid', 'u:test01')->value('registered'));
    }
}
