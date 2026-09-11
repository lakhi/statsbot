<?php

namespace Tests\Feature;

use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AllocationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['study.phase' => 'test', 'study.admin_uids' => ['u:admin']]);

        $_SERVER['uid'] = 'u:test01';
        $_SERVER['givenName'] = 'Test';
        $_SERVER['sn'] = 'Student';
    }

    protected function tearDown(): void
    {
        unset($_SERVER['uid'], $_SERVER['givenName'], $_SERVER['sn']);
        parent::tearDown();
    }

    /** @param array<int,string> $arms */
    private function seedSequence(string $stratum, array $arms, string $phase = 'test'): void
    {
        foreach ($arms as $i => $arm) {
            DB::table('allocation_slot')->insert([
                'phase' => $phase, 'stratum' => $stratum, 'seq' => $i + 1, 'arm' => $arm,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    private function roster(string $uid, string $status): void
    {
        DB::table('roster')->insert([
            'uid' => $uid, 'status' => $status, 'provenance' => 'test',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function register(string $uid): \Illuminate\Testing\TestResponse
    {
        $_SERVER['uid'] = $uid;

        return $this->postJson('/api/register', []);
    }

    public function test_slots_are_claimed_in_sequence_order_within_a_stratum(): void
    {
        $this->seedSequence('bachelor', ['rag', 'no_rag', 'no_rag', 'rag']);
        $this->roster('u:one', 'bachelor');
        $this->roster('u:two', 'bachelor');

        $this->register('u:one')->assertSuccessful()->assertJsonPath('arm', 'rag');
        $this->register('u:two')->assertSuccessful()->assertJsonPath('arm', 'no_rag');
    }

    public function test_each_stratum_has_its_own_independent_sequence(): void
    {
        // the test seed opens the two strata on OPPOSITE arms precisely so that a
        // single shared sequence would show up here rather than look plausible
        $this->seedSequence('bachelor', ['rag', 'no_rag']);
        $this->seedSequence('master', ['no_rag', 'rag']);
        $this->roster('u:ba', 'bachelor');
        $this->roster('u:ma', 'master');

        $this->register('u:ba')->assertJsonPath('arm', 'rag');
        $this->register('u:ma')->assertJsonPath('arm', 'no_rag');
    }

    public function test_re_registering_does_not_consume_a_second_slot(): void
    {
        $this->seedSequence('bachelor', ['rag', 'no_rag']);
        $this->roster('u:one', 'bachelor');

        $this->register('u:one')->assertJsonPath('arm', 'rag');
        $this->register('u:one')->assertJsonPath('arm', 'rag');

        $this->assertSame(1, DB::table('allocation_slot')->whereNotNull('claimed_uid')->count(),
            'a re-submitted disclaimer must never re-randomise a participant');
    }

    public function test_a_uid_absent_from_the_roster_falls_to_the_unknown_stratum(): void
    {
        $this->seedSequence('unknown', ['no_rag']);

        $this->register('u:walkin')->assertSuccessful()->assertJsonPath('arm', 'no_rag');

        $this->assertSame('unknown',
            DB::table('allocation_slot')->where('claimed_uid', 'u:walkin')->value('stratum'));
    }

    public function test_an_exhausted_sequence_refuses_rather_than_improvising(): void
    {
        // no slots seeded for this stratum at all
        $this->roster('u:one', 'bachelor');

        $this->register('u:one')->assertStatus(503);

        $this->assertNull(Student::where('uid', 'u:one')->value('arm'));
    }

    public function test_the_study_sequence_is_untouched_by_a_test_phase_claim(): void
    {
        $this->seedSequence('bachelor', ['rag'], 'test');
        $this->seedSequence('bachelor', ['no_rag', 'rag'], 'study');
        $this->roster('u:one', 'bachelor');

        $this->register('u:one')->assertJsonPath('arm', 'rag');

        $this->assertSame(0,
            DB::table('allocation_slot')->where('phase', 'study')->whereNotNull('claimed_uid')->count(),
            'a rehearsal must never burn a real study allocation');
    }

    public function test_phase_off_registers_without_allocating(): void
    {
        config(['study.phase' => 'off']);
        $this->seedSequence('bachelor', ['rag']);
        $this->roster('u:one', 'bachelor');

        $this->register('u:one')->assertSuccessful()->assertJsonPath('arm', null);

        $this->assertSame(0, DB::table('allocation_slot')->whereNotNull('claimed_uid')->count());
    }

    public function test_students_arm_and_the_claimed_slot_always_agree(): void
    {
        $this->seedSequence('master', ['no_rag', 'rag']);
        $this->roster('u:one', 'master');
        $this->roster('u:two', 'master');

        $this->register('u:one');
        $this->register('u:two');

        foreach (DB::table('allocation_slot')->whereNotNull('claimed_uid')->get() as $slot) {
            $this->assertSame($slot->arm, Student::where('uid', $slot->claimed_uid)->value('arm'),
                'the monitor cross-check must hold: slot arm == students.arm');
        }
    }

    public function test_the_monitor_is_refused_to_non_admins(): void
    {
        $_SERVER['uid'] = 'u:nobody';
        $this->getJson('/api/study/monitor')->assertStatus(403);
    }

    public function test_the_monitor_reports_buckets_and_the_ledger(): void
    {
        $this->seedSequence('bachelor', ['rag', 'no_rag']);
        $this->roster('u:one', 'bachelor');
        $this->register('u:one');

        $_SERVER['uid'] = 'u:admin';
        $this->getJson('/api/study/monitor')
            ->assertOk()
            ->assertJsonPath('buckets.bachelor.rag', 1)
            ->assertJsonPath('buckets.bachelor.no_rag', 0)
            ->assertJsonPath('slots_remaining.bachelor', 1)
            ->assertJsonPath('ledger.0.uid', 'u:one')
            ->assertJsonPath('ledger.0.consistent', true);
    }

    public function test_reset_releases_test_slots_and_sends_students_back_to_the_disclaimer(): void
    {
        $this->seedSequence('bachelor', ['rag', 'no_rag']);
        $this->roster('u:one', 'bachelor');
        $this->register('u:one');

        Student::where('uid', 'u:one')->update(['token_left' => 5]);

        $_SERVER['uid'] = 'u:admin';
        $this->postJson('/api/study/reset')->assertOk()->assertJsonPath('released', 1);

        $this->assertSame(0, DB::table('allocation_slot')->whereNotNull('claimed_uid')->count());

        $student = Student::where('uid', 'u:one')->first();
        $this->assertNull($student->arm);
        $this->assertSame(0, (int) $student->registered);
        $this->assertSame((int) $student->token_limit, (int) $student->token_left);
    }

    public function test_reset_is_refused_outside_the_test_phase(): void
    {
        // releasing slots mid-study would silently re-randomise a live
        // participant - the one failure here that corrupts data without erroring
        config(['study.phase' => 'study']);
        $_SERVER['uid'] = 'u:admin';

        $this->postJson('/api/study/reset')->assertStatus(403);
    }
}
