<?php

namespace Tests\Feature\Elections;

use App\Enums\Level;
use App\Enums\Role;
use App\Models\Election;
use App\Models\RollEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class NominalRollTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->role(Role::Admin)->create();
    }

    private function csv(string $content, string $name = 'roll.csv'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $content);
    }

    #[Test]
    public function only_admins_see_and_import_the_roll(): void
    {
        foreach ([Role::Student, Role::CourseRep, Role::Governor] as $role) {
            $this->actingAs(User::factory()->role($role)->create())->get(route('roll.index'))->assertForbidden();
            $this->post(route('roll.store'), ['roll' => $this->csv("F/ND/24/1234567\n"), 'mode' => 'replace'])->assertForbidden();
        }

        $this->assertSame(0, RollEntry::count());
        $this->actingAs($this->admin)->get(route('roll.index'))->assertOk()->assertSee('The roll is empty');
    }

    #[Test]
    public function a_spreadsheet_export_is_read_by_its_headings(): void
    {
        $this->actingAs($this->admin)->post(route('roll.store'), [
            'roll' => $this->csv("\u{FEFF}S/N,Matric No.,Full Name,Class\n1,f/nd/24/1234567,Ada  Obi,nd 2\n2,not a matric,Someone,ND1\n3,F/ND/24/1234567,Duplicate,ND1\n4,HND/2021/CS/1234,Tunde Bello,HND1\n"),
            'mode' => 'replace',
        ])->assertRedirect(route('roll.index'))->assertSessionHas('import.total', 2);

        $ada = RollEntry::where('matric_number', 'F/ND/24/1234567')->firstOrFail();
        $this->assertSame('Ada Obi', $ada->fullname);
        $this->assertSame(Level::ND2, $ada->level);
        $this->assertSame(1, session('import')['skippedCount']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'roll_imported', 'user_id' => $this->admin->id]);

        $this->get(route('roll.index', ['q' => 'tunde']))->assertSee('HND/2021/CS/1234')->assertDontSee('F/ND/24/1234567');
    }

    #[Test]
    public function replacing_the_roll_drops_graduates_and_adding_keeps_everyone(): void
    {
        RollEntry::create(['matric_number' => 'F/ND/19/0000001', 'fullname' => 'Graduated', 'level' => 'HND2']);
        RollEntry::create(['matric_number' => 'F/ND/24/0000002', 'fullname' => 'Staying', 'level' => 'ND1']);

        $this->actingAs($this->admin)->post(route('roll.store'), [
            'roll' => $this->csv("F/ND/24/0000002;ND2\nF/ND/25/0000003;ND1\n"),
            'mode' => 'replace',
        ])->assertSessionHas('import', fn (array $import) => $import['total'] === 2 && $import['added'] === 1 && $import['removed'] === 1);

        $this->assertDatabaseMissing('nominal_roll', ['matric_number' => 'F/ND/19/0000001']);
        $this->assertSame(Level::ND2, RollEntry::where('matric_number', 'F/ND/24/0000002')->firstOrFail()->level);

        $this->post(route('roll.store'), ['roll' => $this->csv("F/HD/23/0000004\n", 'extra.txt'), 'mode' => 'add']);
        $this->assertSame(3, RollEntry::count());
    }

    #[Test]
    public function a_file_without_matric_numbers_is_refused(): void
    {
        $this->actingAs($this->admin)
            ->post(route('roll.store'), ['roll' => $this->csv("name,phone\nAda,0803\n"), 'mode' => 'replace'])
            ->assertSessionHasErrors('roll');

        $this->post(route('roll.store'), ['mode' => 'replace'])->assertSessionHasErrors(['roll' => 'Choose the CSV file with the nominal roll.']);
        $this->assertSame(0, RollEntry::count());
    }

    #[Test]
    public function only_students_on_the_roll_can_vote_and_its_level_counts(): void
    {
        $election = Election::factory()->open()->withBallot(['President' => 1])->create(['roll_only' => true, 'levels' => ['ND2']]);
        $position = $election->positions()->firstOrFail();
        $choices = ['choices' => [$position->id => $position->candidates()->firstOrFail()->id]];

        // Says ND2 at signup, but the roll says ND1.
        $claimsNd2 = User::factory()->create(['level' => Level::ND2, 'matric_number' => 'F/ND/24/0000010']);
        RollEntry::create(['matric_number' => 'F/ND/24/0000010', 'level' => 'ND1']);
        // Graduated: not on the roll.
        $graduate = User::factory()->create(['level' => Level::ND2, 'matric_number' => 'F/ND/20/0000011']);
        // On the roll without a level: their own level counts.
        $current = User::factory()->create(['level' => Level::ND2, 'matric_number' => 'F/ND/24/0000012']);
        RollEntry::create(['matric_number' => 'F/ND/24/0000012']);

        $this->assertSame('ND2 students, on the nominal roll', $election->eligibilitySummary());
        $this->assertSame(1, $election->electorate()->count());

        $this->actingAs($graduate)->get(route('elections.show', $election))->assertSee('isn&#039;t on the current nominal roll', false);
        $this->post(route('elections.vote', $election), $choices)->assertSessionHasErrors('ballot');
        $this->actingAs($claimsNd2)->post(route('elections.vote', $election), $choices)->assertSessionHasErrors('ballot');
        $this->actingAs($current)->post(route('elections.vote', $election), $choices)->assertSessionHasNoErrors();

        $this->assertSame(1, $election->ballotCount());
    }

    #[Test]
    public function an_election_limited_to_the_roll_cannot_launch_while_it_is_empty(): void
    {
        $election = Election::factory()->withBallot(['President' => 1])->create(['roll_only' => true]);

        $this->actingAs($this->admin)
            ->post(route('elections.manage.launch', $election), ['hours' => 6])
            ->assertSessionHasErrors('launch');

        RollEntry::create(['matric_number' => 'F/ND/24/0000001']);
        $this->post(route('elections.manage.launch', $election), ['hours' => 6])->assertSessionHasNoErrors();
    }

    #[Test]
    public function new_elections_are_limited_to_the_roll_by_default(): void
    {
        $this->actingAs($this->admin)->get(route('elections.manage.create'))
            ->assertSee('Only students on the nominal roll')
            ->assertSee('name="roll_only" value="1" class="mt-1 size-4 shrink-0 accent-[var(--primary)]" checked', false);

        $this->post(route('elections.manage.store'), ['title' => 'With roll', 'roll_only' => '1']);
        $this->assertTrue(Election::where('title', 'With roll')->firstOrFail()->roll_only);
    }
}
