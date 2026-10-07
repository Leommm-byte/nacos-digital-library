<?php

namespace Tests\Feature\Elections;

use App\Enums\Level;
use App\Enums\Role;
use App\Models\Election;
use App\Models\ElectionCandidate;
use App\Models\ElectionPosition;
use App\Models\User;
use App\Support\Elections\LiveResults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class VotingTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->student = User::factory()->create(['level' => Level::ND2, 'matric_number' => 'F/ND/24/1234567']);
    }

    private function election(): Election
    {
        return Election::factory()->open()->withBallot(['President' => 2, 'Treasurer' => 2])->create(['title' => 'Executive Council']);
    }

    private function position(Election $election, string $title): ElectionPosition
    {
        return $election->positions()->where('title', $title)->firstOrFail();
    }

    private function candidate(Election $election, string $position, int $index = 0): ElectionCandidate
    {
        return $this->position($election, $position)->candidates()->orderBy('sort_order')->skip($index)->firstOrFail();
    }

    #[Test]
    public function with_no_open_election_students_see_that_none_is_active(): void
    {
        Election::factory()->create(['title' => 'Secret Draft']);

        $this->actingAs($this->student)
            ->get(route('elections.index'))
            ->assertOk()
            ->assertSee('No election active')
            ->assertDontSee('Secret Draft');
    }

    #[Test]
    public function drafts_are_hidden_from_everyone_but_admins(): void
    {
        $draft = Election::factory()->create();

        $this->get(route('elections.show', $draft))->assertNotFound();
        $this->actingAs($this->student)->get(route('elections.show', $draft))->assertNotFound();
        $this->actingAs(User::factory()->role(Role::Governor)->create())->get(route('elections.show', $draft))->assertNotFound();
        $this->actingAs(User::factory()->role(Role::Admin)->create())->get(route('elections.show', $draft))->assertOk();

        // And no results file is ever written for a draft.
        $this->assertNull(LiveResults::read($draft));
        $this->assertFileDoesNotExist(LiveResults::path($draft));
    }

    #[Test]
    public function guests_can_follow_an_open_election_but_must_log_in_to_vote(): void
    {
        $election = $this->election();

        $this->get(route('elections.index'))->assertOk()->assertSee('Executive Council')->assertSee('Log in to vote');
        $this->get(route('elections.show', $election))->assertOk()->assertSee('Live results')->assertSee('Log in to vote');
        $this->post(route('elections.vote', $election))->assertRedirect(route('login'));
    }

    #[Test]
    public function a_student_votes_once_and_the_ballot_stays_secret(): void
    {
        $election = $this->election();
        $president = $this->candidate($election, 'President', 1);
        $treasurer = $this->candidate($election, 'Treasurer');

        $this->actingAs($this->student)
            ->get(route('elections.show', $election))
            ->assertOk()
            ->assertSee('Cast my vote')
            ->assertSee($president->name);

        $this->post(route('elections.vote', $election), ['choices' => [
            $this->position($election, 'President')->id => $president->id,
            $this->position($election, 'Treasurer')->id => $treasurer->id,
        ]])->assertRedirect(route('elections.show', $election));

        $this->assertSame(1, ElectionCandidate::findOrFail($president->id)->votes_count);
        $this->assertSame(1, ElectionCandidate::findOrFail($treasurer->id)->votes_count);
        $this->assertSame(2, DB::table('election_votes')->count());
        $this->assertTrue($election->hasVoted($this->student));

        // The votes say nothing about who cast them or when.
        $this->assertSame(['id', 'election_id', 'position_id', 'candidate_id'], array_keys((array) DB::table('election_votes')->first()));
        $this->assertDatabaseHas('audit_logs', ['action' => 'election_voted', 'user_id' => $this->student->id]);

        $this->get(route('elections.show', $election))->assertSee('Your vote is in')->assertDontSee('Cast my vote');

        // A second ballot is refused and changes nothing.
        $this->post(route('elections.vote', $election), ['choices' => [$this->position($election, 'President')->id => $president->id]])
            ->assertSessionHas('status', 'You have already voted in this election.');
        $this->assertSame(1, ElectionCandidate::findOrFail($president->id)->votes_count);
        $this->assertSame(1, DB::table('election_voters')->count());
    }

    #[Test]
    public function a_position_can_be_skipped_but_not_the_whole_ballot(): void
    {
        $election = $this->election();
        $president = $this->position($election, 'President');
        $treasurer = $this->position($election, 'Treasurer');

        $this->actingAs($this->student)
            ->post(route('elections.vote', $election), ['choices' => [$president->id => '', $treasurer->id => '']])
            ->assertSessionHasErrors(['ballot' => 'Choose a candidate for at least one position.']);
        $this->assertFalse($election->hasVoted($this->student));

        $this->post(route('elections.vote', $election), ['choices' => [
            $president->id => $this->candidate($election, 'President')->id,
            $treasurer->id => '',
        ]])->assertSessionHasNoErrors();

        $this->assertSame(1, DB::table('election_votes')->count());
    }

    #[Test]
    public function candidates_must_belong_to_the_position_and_the_election(): void
    {
        $election = $this->election();
        $other = $this->election();

        $cases = [
            // A treasurer candidate given as a president vote.
            [$this->position($election, 'President')->id => $this->candidate($election, 'Treasurer')->id],
            // A position and candidate from another election.
            [$this->position($other, 'President')->id => $this->candidate($other, 'President')->id],
            [$this->position($election, 'President')->id => 'abc'],
        ];

        foreach ($cases as $choices) {
            $this->actingAs($this->student)
                ->post(route('elections.vote', $election), ['choices' => $choices])
                ->assertSessionHasErrors('ballot');
        }

        $this->assertSame(0, DB::table('election_votes')->count());
        $this->assertSame(0, DB::table('election_voters')->count());
    }

    #[Test]
    public function votes_are_refused_once_the_time_is_up_even_before_the_election_is_closed(): void
    {
        $election = $this->election();
        $choices = [$this->position($election, 'President')->id => $this->candidate($election, 'President')->id];

        $this->travel(7)->hours();

        $this->actingAs($this->student)
            ->post(route('elections.vote', $election), ['choices' => $choices])
            ->assertSessionHasErrors(['ballot' => 'Voting for this election has closed.']);

        $closed = Election::factory()->closed()->withBallot()->create();
        $this->post(route('elections.vote', $closed), ['choices' => [
            $closed->positions()->firstOrFail()->id => $closed->positions()->firstOrFail()->candidates()->firstOrFail()->id,
        ]])->assertSessionHasErrors('ballot');

        $this->assertSame(0, DB::table('election_votes')->count());
    }

    #[Test]
    public function eligibility_can_be_limited_by_level_and_entry_year(): void
    {
        $election = $this->election();
        $election->update(['levels' => ['ND2', 'HND1'], 'entry_year_from' => 2024, 'entry_year_to' => 2025]);
        $choices = [$this->position($election, 'President')->id => $this->candidate($election, 'President')->id];

        $wrongLevel = User::factory()->create(['level' => Level::ND1, 'matric_number' => 'F/ND/24/7654321']);
        $tooEarly = User::factory()->create(['level' => Level::ND2, 'matric_number' => 'F/ND/23/7654322']);
        $oldFormat = User::factory()->create(['level' => Level::HND1, 'matric_number' => 'HND/2025/CS/1234']);

        $this->assertSame('ND2 and HND1 students who entered from 2024 to 2025', $election->eligibilitySummary());
        $this->assertSame(2, $election->electorate()->count());

        foreach ([$wrongLevel, $tooEarly] as $user) {
            $this->actingAs($user)->get(route('elections.show', $election))->assertSee("You can't vote in this election")->assertDontSee('Cast my vote');
            $this->post(route('elections.vote', $election), ['choices' => $choices])
                ->assertSessionHasErrors(['ballot' => 'This election is for ND2 and HND1 students who entered from 2024 to 2025.']);
        }

        foreach ([$this->student, $oldFormat] as $user) {
            $this->actingAs($user)->post(route('elections.vote', $election), ['choices' => $choices])->assertSessionHasNoErrors();
        }

        $this->assertSame(2, $election->ballotCount());
    }

    #[Test]
    public function every_ballot_updates_the_static_results_file(): void
    {
        $election = $this->election();
        LiveResults::publish($election);
        $president = $this->position($election, 'President');
        $choice = $this->candidate($election, 'President');

        $voters = User::factory()->count(3)->create();

        foreach ($voters as $i => $voter) {
            $this->actingAs($voter)->post(route('elections.vote', $election), ['choices' => [$president->id => $choice->id]]);

            $published = LiveResults::read($election);
            $this->assertNotNull($published);
            // Voters see their own vote counted straight away.
            $this->assertSame($i + 1, $published['ballots']);
        }

        $json = (string) file_get_contents(LiveResults::path($election));
        $data = json_decode($json, true);
        $this->assertSame(3, $data['positions'][0]['candidates'][0]['votes']);
        $this->assertSame(100, (int) $data['positions'][0]['candidates'][0]['percent']);
        $this->assertSame(3, $data['positions'][1]['skipped']);
        // Totals only: no voters in the file.
        foreach ($voters as $voter) {
            $this->assertStringNotContainsString($voter->matric_number, $json);
        }

        // The page shows the published numbers.
        $this->get(route('elections.show', $election))->assertSee('data-live-results', false)->assertSee('Leading');
    }

    #[Test]
    public function candidate_names_are_escaped(): void
    {
        $election = $this->election();
        $this->candidate($election, 'President')->update(['name' => '<script>alert(1)</script>']);

        $this->actingAs($this->student)
            ->get(route('elections.show', $election))
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
    }

    #[Test]
    public function the_dashboard_asks_eligible_students_who_have_not_voted(): void
    {
        $election = $this->election();

        $this->actingAs($this->student)->get('/')->assertSee('Vote now')->assertSee('Executive Council');

        $this->post(route('elections.vote', $election), ['choices' => [
            $this->position($election, 'President')->id => $this->candidate($election, 'President')->id,
        ]]);

        $this->get('/')->assertDontSee('Vote now')->assertSee('You voted in &quot;Executive Council&quot;', false);
    }
}
