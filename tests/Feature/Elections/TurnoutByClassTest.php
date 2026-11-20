<?php

namespace Tests\Feature\Elections;

use App\Enums\Role;
use App\Models\Election;
use App\Models\RollEntry;
use App\Models\User;
use App\Support\Elections\TurnoutByClass;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Turnout by class: who could vote and how many did, class by class, from
 * the record that someone voted (never from the ballots).
 */
class TurnoutByClassTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->role(Role::Admin)->create();
    }

    /**
     * A student on the roll in the given class (the account may say
     * otherwise: the roll counts).
     *
     * @param  array<string, mixed>  $account
     */
    private function student(string $programme, string $level, array $account = []): User
    {
        $user = User::factory()->create(['programme' => $programme, 'level' => $level, ...$account]);
        RollEntry::create(['matric_number' => $user->matric_number, 'fullname' => $user->fullname, 'programme' => $programme, 'level' => $level]);

        return $user;
    }

    private function voted(Election $election, User ...$users): void
    {
        foreach ($users as $user) {
            $election->voters()->attach($user->id, ['voted_at' => now()]);
        }
    }

    #[Test]
    public function it_counts_who_could_vote_and_who_did_class_by_class(): void
    {
        $election = Election::factory()->open()->withBallot(['President' => 2])->create(['roll_only' => true]);

        $a = $this->student('full_time', 'ND1');
        $b = $this->student('full_time', 'ND1');
        // The account says ND2; the roll's class counts.
        $c = $this->student('full_time', 'ND1', ['level' => 'ND2']);
        $this->student('part_time', 'ND2');
        $this->student('part_time', 'ND2', ['vote_held_at' => now()]);
        $this->voted($election, $a, $b);

        $rows = collect(TurnoutByClass::for($election))->keyBy('label');

        // The admin isn't on the roll, so isn't counted.
        $this->assertSame(['ND1 Full-time', 'ND2 Part-time'], $rows->keys()->all());
        $this->assertSame(['eligible' => 3, 'held' => 0, 'voted' => 2, 'turnout' => 66.7], array_intersect_key($rows['ND1 Full-time'], array_flip(['eligible', 'held', 'voted', 'turnout'])));
        $this->assertSame(['eligible' => 1, 'held' => 1, 'voted' => 0, 'turnout' => 0.0], array_intersect_key($rows['ND2 Part-time'], array_flip(['eligible', 'held', 'voted', 'turnout'])));

        $this->actingAs($this->admin)->get(route('elections.manage.show', $election))->assertOk()
            ->assertSeeInOrder(['Turnout by class', 'ND1 Full-time', '66.7%', 'ND2 Part-time']);

        $csv = $this->actingAs($this->admin)->get(route('elections.manage.turnout.csv', $election))->assertOk()->streamedContent();
        $this->assertStringContainsString("Class,\"Could vote\",Voted,\"Turnout %\",\"On hold\"\n\"ND1 Full-time\",3,2,66.7,0\n\"ND2 Part-time\",1,0,0,1\n\"All classes\",4,2,50,1", $csv);

        $this->actingAs($this->admin)->get(route('elections.manage.results.print', $election))->assertOk()
            ->assertSeeInOrder(['Turnout by class', 'ND1 Full-time', '66.7']);
    }

    #[Test]
    public function only_election_admins_see_it_and_not_before_voting_opens(): void
    {
        $draft = Election::factory()->create();
        $open = Election::factory()->open()->withBallot(['President' => 2])->create();

        $this->actingAs($this->admin)->get(route('elections.manage.show', $draft))->assertOk()->assertDontSee('Turnout by class');
        $this->actingAs($this->admin)->get(route('elections.manage.turnout.csv', $draft))->assertNotFound();

        $student = User::factory()->create();
        $this->actingAs($student)->get(route('elections.manage.turnout.csv', $open))->assertForbidden();
        $this->actingAs($student)->get(route('elections.show', $open))->assertOk()->assertDontSee('Turnout by class');
    }
}
