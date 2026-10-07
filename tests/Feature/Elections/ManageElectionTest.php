<?php

namespace Tests\Feature\Elections;

use App\Enums\ElectionStatus;
use App\Enums\Level;
use App\Enums\Role;
use App\Models\Election;
use App\Models\ElectionCandidate;
use App\Models\User;
use App\Support\Elections\LiveResults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ManageElectionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->role(Role::Admin)->create();
    }

    #[Test]
    public function only_admins_manage_elections(): void
    {
        $election = Election::factory()->create();

        foreach ([Role::Student, Role::CourseRep, Role::Governor] as $role) {
            $user = User::factory()->role($role)->create();

            $this->actingAs($user)->get(route('elections.manage'))->assertForbidden();
            $this->post(route('elections.manage.store'), ['title' => 'x'])->assertForbidden();
            $this->get(route('elections.manage.show', $election))->assertForbidden();
            $this->post(route('elections.manage.launch', $election), ['hours' => 1])->assertForbidden();
        }

        $this->actingAs($this->admin)->get(route('elections.manage'))->assertOk()->assertSee('Manage elections');
        $this->get(route('elections.index'))->assertSee('Manage elections');
    }

    #[Test]
    public function an_admin_creates_an_election_limited_by_level_and_entry_year(): void
    {
        $this->actingAs($this->admin)->get(route('elections.manage.create'))->assertOk()->assertSee('Who can vote')->assertSee('2024 (…/24/…)');

        $this->post(route('elections.manage.store'), [
            'title' => 'HND Class Reps',
            'levels' => ['HND1', 'HND2'],
            'entry_year_from' => '2022',
            'entry_year_to' => '2024',
        ])->assertSessionHasNoErrors();

        $election = Election::firstOrFail();
        $this->assertSame(ElectionStatus::Draft, $election->status);
        $this->assertSame(['HND1', 'HND2'], $election->levels);
        $this->assertSame(2022, $election->entry_year_from);
        $this->assertSame(2024, $election->entry_year_to);
        $this->assertSame($this->admin->id, $election->created_by);
        $this->assertDatabaseHas('audit_logs', ['action' => 'election_created', 'subject_id' => $election->id]);

        $this->get(route('elections.manage.show', $election))->assertOk()->assertSee('HND1 and HND2 students who entered from 2022 to 2024');
    }

    #[Test]
    public function every_level_or_no_level_means_everyone_and_years_must_be_in_order(): void
    {
        $this->actingAs($this->admin)->post(route('elections.manage.store'), [
            'title' => 'Everyone',
            'levels' => array_map(fn (Level $level) => $level->value, Level::cases()),
        ]);
        $this->assertNull(Election::firstOrFail()->levels);
        $this->assertSame('All students', Election::firstOrFail()->eligibilitySummary());

        $this->post(route('elections.manage.store'), ['title' => 'Backwards', 'entry_year_from' => '2025', 'entry_year_to' => '2023'])
            ->assertSessionHasErrors(['entry_year_to' => 'The last entry year can\'t be before the first.']);
        $this->post(route('elections.manage.store'), ['title' => 'Bad', 'levels' => ['PhD']])->assertSessionHasErrors('levels.0');

        // An open-ended range is fine.
        $this->post(route('elections.manage.store'), ['title' => 'From 2024', 'entry_year_from' => '2024'])->assertSessionHasNoErrors();
        $this->assertSame('All students who entered in 2024 or later', Election::where('title', 'From 2024')->firstOrFail()->eligibilitySummary());
    }

    #[Test]
    public function positions_and_candidates_are_set_up_on_a_draft(): void
    {
        $election = Election::factory()->create();
        $student = User::factory()->create(['matric_number' => 'F/ND/24/1111111']);

        $this->actingAs($this->admin)->post(route('elections.manage.positions.store', $election), ['title' => 'President'])->assertSessionHasNoErrors();
        $this->post(route('elections.manage.positions.store', $election), ['title' => 'Treasurer']);
        $president = $election->positions()->where('title', 'President')->firstOrFail();
        $treasurer = $election->positions()->where('title', 'Treasurer')->firstOrFail();

        $this->post(route('elections.manage.candidates.store', [$election, $president]), [
            'name' => 'Ada Obi',
            'matric_number' => 'f/nd/24/1111111',
            'manifesto' => 'A library on every phone.',
        ])->assertSessionHasNoErrors();

        $candidate = $president->candidates()->firstOrFail();
        $this->assertSame('F/ND/24/1111111', $candidate->matric_number);
        $this->assertSame($student->id, $candidate->user_id);

        // The same student can't stand twice in one election.
        $this->post(route('elections.manage.candidates.store', [$election, $treasurer]), ['name' => 'Ada Obi', 'matric_number' => 'F/ND/24/1111111'])
            ->assertSessionHasErrorsIn('candidate-'.$treasurer->id, 'matric_number');
        $this->post(route('elections.manage.candidates.store', [$election, $treasurer]), ['name' => 'Bad', 'matric_number' => 'nonsense'])
            ->assertSessionHasErrorsIn('candidate-'.$treasurer->id, 'matric_number');

        // Treasurer first.
        $this->post(route('elections.manage.positions.move', [$election, $treasurer]), ['direction' => 'up']);
        $this->assertSame(['Treasurer', 'President'], $election->positions()->pluck('title')->all());

        $this->put(route('elections.manage.candidates.update', [$election, $president, $candidate]), ['name' => 'Ada N. Obi', 'matric_number' => ''])
            ->assertSessionHasNoErrors();
        $this->assertNull(ElectionCandidate::findOrFail($candidate->id)->user_id);

        $this->get(route('elections.manage.show', $election))->assertOk()->assertSee('Ada N. Obi')->assertSee('Add a candidate for Treasurer, or remove that position.');
    }

    #[Test]
    public function nested_routes_only_reach_their_own_election(): void
    {
        $election = Election::factory()->withBallot(['President' => 1])->create();
        $other = Election::factory()->withBallot(['President' => 1])->create();
        $otherPosition = $other->positions()->firstOrFail();
        $otherCandidate = $otherPosition->candidates()->firstOrFail();

        $this->actingAs($this->admin)
            ->delete(route('elections.manage.positions.destroy', [$election, $otherPosition]))
            ->assertNotFound();
        $this->delete(route('elections.manage.candidates.destroy', [$election, $election->positions()->firstOrFail(), $otherCandidate]))
            ->assertNotFound();

        $this->assertModelExists($otherCandidate);
    }

    #[Test]
    public function launching_needs_a_full_ballot_and_freezes_it(): void
    {
        $election = Election::factory()->create();
        $this->actingAs($this->admin)->post(route('elections.manage.launch', $election), ['hours' => 6])
            ->assertSessionHasErrors(['launch' => 'Add at least one position before launching.']);

        $election = Election::factory()->withBallot(['President' => 2])->create();
        $this->post(route('elections.manage.launch', $election), ['hours' => 5])->assertSessionHasErrors('hours');

        $this->travelTo(now()->startOfMinute());
        $this->post(route('elections.manage.launch', $election), ['hours' => 6])->assertSessionHasNoErrors();

        $election->refresh();
        $this->assertSame(ElectionStatus::Open, $election->status);
        $this->assertTrue($election->ends_at?->equalTo(now()->addHours(6)));
        $this->assertFileExists(LiveResults::path($election));
        $this->assertDatabaseHas('audit_logs', ['action' => 'election_launched', 'subject_id' => $election->id]);

        // Nothing about it can change now.
        $position = $election->positions()->firstOrFail();
        $this->put(route('elections.manage.update', $election), ['title' => 'Changed'])->assertForbidden();
        $this->post(route('elections.manage.positions.store', $election), ['title' => 'New'])->assertForbidden();
        $this->delete(route('elections.manage.candidates.destroy', [$election, $position, $position->candidates()->firstOrFail()]))->assertForbidden();
        $this->post(route('elections.manage.launch', $election), ['hours' => 6])->assertForbidden();
        $this->delete(route('elections.manage.destroy', $election))->assertForbidden();

        $this->get(route('elections.manage.show', $election))->assertOk()->assertSee('Live standings')->assertSee('Stop voting now');
    }

    #[Test]
    public function stopping_early_closes_for_good_and_publishes_the_final_results(): void
    {
        config(['elections.batch' => 50]);
        $election = Election::factory()->open()->withBallot(['President' => 2])->create();
        $position = $election->positions()->firstOrFail();
        $student = User::factory()->create();
        LiveResults::publish($election, true);

        $this->actingAs($student)->post(route('elections.vote', $election), ['choices' => [$position->id => $position->candidates()->firstOrFail()->id]]);
        $this->assertSame(0, LiveResults::read($election)['ballots'] ?? null);

        $this->actingAs($this->admin)->post(route('elections.manage.close', $election))->assertRedirect(route('elections.manage.show', $election));

        $election->refresh();
        $this->assertSame(ElectionStatus::Closed, $election->status);
        $this->assertNotNull($election->closed_at);
        $results = LiveResults::read($election);
        $this->assertTrue($results['final'] ?? false);
        $this->assertSame(1, $results['ballots'] ?? null);
        $this->assertDatabaseHas('audit_logs', ['action' => 'election_closed', 'user_id' => $this->admin->id]);

        // Closed is final: no reopening, no relaunch.
        $this->post(route('elections.manage.close', $election))->assertStatus(409);
        $this->post(route('elections.manage.launch', $election), ['hours' => 1])->assertForbidden();

        $this->get(route('elections.show', $election))->assertSee('Final results')->assertSee('Winner');
    }

    #[Test]
    public function only_drafts_can_be_deleted(): void
    {
        $draft = Election::factory()->withBallot()->create();

        $this->actingAs($this->admin)->delete(route('elections.manage.destroy', $draft))->assertRedirect(route('elections.manage'));
        $this->assertModelMissing($draft);
        $this->assertDatabaseCount('election_candidates', 0);
    }
}
