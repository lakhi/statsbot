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

    /**
     * The arm /register allocated. Read from the database, because no response
     * carries it - a student who could see their arm would be unblinded.
     */
    private function armAfterRegistering(string $uid): ?string
    {
        $this->register($uid)->assertSuccessful()->assertJsonMissingPath('arm');

        return DB::table('students')->where('uid', $uid)->value('arm');
    }

    public function test_slots_are_claimed_in_sequence_order_within_a_stratum(): void
    {
        $this->seedSequence('bachelor', ['rag', 'no_rag', 'no_rag', 'rag']);
        $this->roster('u:one', 'bachelor');
        $this->roster('u:two', 'bachelor');

        $this->assertSame('rag', $this->armAfterRegistering('u:one'));
        $this->assertSame('no_rag', $this->armAfterRegistering('u:two'));
    }

    public function test_each_stratum_has_its_own_independent_sequence(): void
    {
        // the test seed opens the two strata on OPPOSITE arms precisely so that a
        // single shared sequence would show up here rather than look plausible
        $this->seedSequence('bachelor', ['rag', 'no_rag']);
        $this->seedSequence('master', ['no_rag', 'rag']);
        $this->roster('u:ba', 'bachelor');
        $this->roster('u:ma', 'master');

        $this->assertSame('rag', $this->armAfterRegistering('u:ba'));
        $this->assertSame('no_rag', $this->armAfterRegistering('u:ma'));
    }

    public function test_re_registering_does_not_consume_a_second_slot(): void
    {
        $this->seedSequence('bachelor', ['rag', 'no_rag']);
        $this->roster('u:one', 'bachelor');

        $this->assertSame('rag', $this->armAfterRegistering('u:one'));
        $this->assertSame('rag', $this->armAfterRegistering('u:one'));

        $this->assertSame(1, DB::table('allocation_slot')->whereNotNull('claimed_uid')->count(),
            'a re-submitted disclaimer must never re-randomise a participant');
    }

    public function test_a_uid_absent_from_the_roster_falls_to_the_unknown_stratum(): void
    {
        $this->seedSequence('unknown', ['no_rag']);

        $this->assertSame('no_rag', $this->armAfterRegistering('u:walkin'));

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

        $this->assertSame('rag', $this->armAfterRegistering('u:one'));

        $this->assertSame(0,
            DB::table('allocation_slot')->where('phase', 'study')->whereNotNull('claimed_uid')->count(),
            'a rehearsal must never burn a real study allocation');
    }

    public function test_phase_off_registers_without_allocating(): void
    {
        config(['study.phase' => 'off']);
        $this->seedSequence('bachelor', ['rag']);
        $this->roster('u:one', 'bachelor');

        $this->assertNull($this->armAfterRegistering('u:one'));

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
        $this->seedSequence('bachelor', ['rag_salient', 'no_rag', 'rag_nonsalient']);
        $this->roster('u:one', 'bachelor');
        $this->register('u:one');

        $_SERVER['uid'] = 'u:admin';
        $this->getJson('/api/study/monitor')
            ->assertOk()
            //every configured arm is a column, even at zero
            ->assertJsonPath('buckets.bachelor.rag_salient', 1)
            ->assertJsonPath('buckets.bachelor.rag_nonsalient', 0)
            ->assertJsonPath('buckets.bachelor.no_rag', 0)
            ->assertJsonPath('slots_remaining.bachelor', 2)
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

    public function test_the_test_seed_is_three_armed_and_balanced_in_every_stratum(): void
    {
        $this->artisan('study:seed-test', ['--with-unknown' => true])->assertSuccessful();

        foreach (['bachelor', 'master', 'unknown'] as $stratum) {
            $perArm = DB::table('allocation_slot')
                ->where('phase', 'test')->where('stratum', $stratum)
                ->selectRaw('arm, COUNT(*) AS n')->groupBy('arm')
                ->pluck('n', 'arm')->map(fn ($n) => (int) $n)->sort()->all();

            $this->assertSame(['no_rag', 'rag_nonsalient', 'rag_salient'], collect($perArm)->keys()->sort()->values()->all());
            $this->assertCount(1, array_unique($perArm), "$stratum is not balanced across arms");
        }

        // different openings, so a shared counter would be visibly wrong
        $openings = DB::table('allocation_slot')->where('seq', 1)->pluck('arm', 'stratum');
        $this->assertNotSame($openings['bachelor'], $openings['master']);
    }

    /** A student holding an arm with no claimed slot behind it. */
    private function orphan(string $uid, string $arm): void
    {
        DB::table('students')->insert([
            'uid' => $uid, 'firstname' => 'O', 'lastname' => 'Rphan',
            'token_limit' => 100, 'token_left' => 7, 'activated' => 1, 'registered' => 1,
            'arm' => $arm, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_a_forced_reseed_releases_claims_and_orphans_first(): void
    {
        // the pod's state: two-arm claims, plus an arm a past --force reseed
        // orphaned. Neither may block the reseed or survive it.
        $this->seedSequence('bachelor', ['rag', 'no_rag']);
        $this->roster('u:one', 'bachelor');
        $this->register('u:one');
        $this->orphan('u:orphan', 'rag');

        $this->artisan('study:seed-test', ['--force' => true, '--with-unknown' => true])->assertSuccessful();

        foreach (['u:one', 'u:orphan'] as $uid) {
            $s = DB::table('students')->where('uid', $uid)->first();
            $this->assertNull($s->arm, "$uid kept an arm");
            $this->assertSame(0, (int) $s->registered, "$uid is not sent back through the disclaimer");
            $this->assertSame((int) $s->token_limit, (int) $s->token_left);
        }

        $this->assertSame(0, DB::table('allocation_slot')->whereNotNull('claimed_uid')->count());
        $this->assertSame(0, DB::table('allocation_slot')->where('arm', 'rag')->count(), 'old two-arm slots remain');
        // bachelor 6 + master 6 + unknown 12
        $this->assertSame(24, DB::table('allocation_slot')->where('phase', 'test')->count());
    }

    public function test_a_released_student_is_allocated_again_at_the_disclaimer(): void
    {
        $this->orphan('u:orphan', 'rag');
        $this->artisan('study:seed-test', ['--force' => true, '--with-unknown' => true])->assertSuccessful();

        // not on the roster -> unknown stratum, which --with-unknown seeded
        $this->assertSame('rag_nonsalient', $this->armAfterRegistering('u:orphan'));
    }

    public function test_release_refuses_once_the_study_has_claimed_a_slot(): void
    {
        // on a stack that ran the study, "an arm with no test slot" is a real
        // participant - nothing may be released there
        $this->seedSequence('bachelor', ['rag_salient'], 'study');
        DB::table('allocation_slot')->where('phase', 'study')->update(['claimed_uid' => 'u:real']);
        $this->orphan('u:real', 'rag_salient');

        $this->artisan('study:seed-test', ['--force' => true])->assertFailed();

        $_SERVER['uid'] = 'u:admin';
        $this->postJson('/api/study/reset')->assertStatus(409);

        $this->assertSame('rag_salient', DB::table('students')->where('uid', 'u:real')->value('arm'));
    }

    public function test_the_monitor_flags_a_claim_under_an_unconfigured_arm(): void
    {
        // 'rag' agrees with itself, so a plain comparison would call it ok while
        // /messages answers that student as control
        $this->seedSequence('bachelor', ['rag']);
        $this->roster('u:one', 'bachelor');
        $this->register('u:one');

        $_SERVER['uid'] = 'u:admin';
        $this->getJson('/api/study/monitor')
            ->assertJsonPath('ledger.0.known_arm', false)
            ->assertJsonPath('ledger.0.consistent', false)
            ->assertJsonPath('buckets.bachelor.rag', 1);

        $html = $this->get('/api/study/monitor?format=html')->assertOk()->getContent();
        $this->assertStringContainsString('<th>rag (unknown)</th>', $html);
        $this->assertStringContainsString('UNKNOWN ARM', $html);
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
