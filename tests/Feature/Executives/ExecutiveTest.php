<?php

namespace Tests\Feature\Executives;

use App\Enums\Role;
use App\Models\Election;
use App\Models\Executive;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ExecutiveTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('private');
        $this->admin = User::factory()->role(Role::Admin)->create();
    }

    #[Test]
    public function anyone_can_see_the_executives_in_order(): void
    {
        $this->get(route('executives.index'))->assertOk()->assertSee('Executives coming soon');

        Executive::create(['name' => 'Second Person', 'position' => 'Vice President', 'sort_order' => 2]);
        Executive::create(['name' => 'First Person', 'position' => 'President', 'details' => 'HND2 SWD', 'sort_order' => 1]);

        $this->get(route('executives.index'))->assertOk()
            ->assertSeeInOrder(['President', 'First Person', 'HND2 SWD', 'Vice President', 'Second Person'])
            ->assertDontSee(route('admin.executives.index'));

        $this->actingAs(User::factory()->create())->get(route('elections.index'))->assertSee(route('executives.index'));
    }

    #[Test]
    public function only_admins_manage_executives(): void
    {
        $executive = Executive::create(['name' => 'Ada', 'position' => 'President']);

        foreach ([Role::Student, Role::CourseRep, Role::Governor] as $role) {
            $this->actingAs(User::factory()->role($role)->create());
            $this->get(route('admin.executives.index'))->assertForbidden();
            $this->post(route('admin.executives.store'), ['name' => 'X', 'position' => 'Y'])->assertForbidden();
            $this->put(route('admin.executives.update', $executive), ['name' => 'X', 'position' => 'Y'])->assertForbidden();
            $this->delete(route('admin.executives.destroy', $executive))->assertForbidden();
        }

        $this->assertSame('Ada', $executive->fresh()?->name);
    }

    #[Test]
    public function an_admin_adds_edits_reorders_and_removes_executives_with_photos(): void
    {
        $this->actingAs($this->admin)->get(route('admin.executives.index'))->assertOk()->assertSee('No executives yet');

        $this->post(route('admin.executives.store'), [
            'name' => '  Ifeoluwa   Bankole ',
            'position' => 'President',
            'details' => 'HND2 SWD',
            'photo' => UploadedFile::fake()->image('me.png', 800, 600),
        ])->assertSessionHasNoErrors();
        $this->post(route('admin.executives.store'), ['name' => 'Chinedu Okafor', 'position' => 'Vice President']);

        [$president, $vice] = Executive::query()->orderBy('sort_order')->get()->all();
        $this->assertSame('Ifeoluwa Bankole', $president->name);
        $this->assertNotNull($president->photo_path);
        Storage::disk('private')->assertExists((string) $president->photo_path);
        $this->assertNull($vice->photo_path);

        // The photo is public and kept by browsers for its version.
        $photo = $this->get((string) $president->photoUrl())->assertOk();
        $this->assertStringContainsString('immutable', (string) $photo->headers->get('Cache-Control'));
        $this->get(route('executives.photo', $vice))->assertNotFound();

        // Reorder: the vice president first.
        $this->put(route('admin.executives.move', $vice), ['direction' => 'up']);
        $this->get(route('executives.index'))->assertSeeInOrder(['Chinedu Okafor', 'Ifeoluwa Bankole']);

        // Edit, and take the photo away.
        $old = (string) $president->photo_path;
        $this->put(route('admin.executives.update', $president), ['name' => 'Ifeoluwa Bankole', 'position' => 'President', 'remove_photo' => '1'])
            ->assertSessionHasNoErrors();
        $this->assertNull($president->fresh()?->photo_path);
        Storage::disk('private')->assertMissing($old);

        $this->post(route('admin.executives.store'), ['name' => '', 'position' => ''])->assertSessionHasErrorsIn('add', ['name', 'position']);

        $this->delete(route('admin.executives.destroy', $vice))->assertRedirect(route('admin.executives.index'));
        $this->assertModelMissing($vice);
        $this->assertDatabaseHas('audit_logs', ['action' => 'executive_removed']);
    }

    #[Test]
    public function the_list_can_be_filled_from_a_closed_elections_winners(): void
    {
        Executive::create(['name' => 'Old Exco', 'position' => 'President']);

        $election = Election::factory()->closed()->withBallot(['President' => 2, 'Treasurer' => 2, 'Secretary' => 2])->create();
        $positions = $election->positions()->orderBy('sort_order')->with(['candidates' => fn ($q) => $q->orderBy('sort_order')])->get();

        // President: a clear winner with a photo; Treasurer: a clear winner;
        // Secretary: a tie, left out.
        [$president, $treasurer, $secretary] = $positions->all();
        $winner = $president->candidates[0];
        $winner->forceFill(['votes_count' => 5, 'photo_path' => 'candidate-photos/x.jpg'])->save();
        Storage::disk('private')->put('candidate-photos/x.jpg', (string) UploadedFile::fake()->image('a.jpg', 10, 10)->get());
        $president->candidates[1]->forceFill(['votes_count' => 2])->save();
        $treasurer->candidates[1]->forceFill(['votes_count' => 3])->save();
        $secretary->candidates[0]->forceFill(['votes_count' => 1])->save();
        $secretary->candidates[1]->forceFill(['votes_count' => 1])->save();

        $this->actingAs($this->admin)->post(route('admin.executives.from-election', $election))
            ->assertRedirect(route('admin.executives.index'))
            ->assertSessionHas('status', fn (string $status) => str_contains($status, '2 executives added') && str_contains($status, 'Tied, so add by hand: '.$secretary->title));

        $executives = Executive::query()->orderBy('sort_order')->get();
        $this->assertSame([$winner->name, $treasurer->candidates[1]->name], $executives->pluck('name')->all());
        $this->assertSame([$president->title, $treasurer->title], $executives->pluck('position')->all());
        $this->assertNotNull($executives[0]->photo_path);
        $this->assertNull($executives[1]->photo_path);
        $this->assertSame(0, Executive::where('name', 'Old Exco')->count());

        // Only closed elections.
        $open = Election::factory()->open()->withBallot()->create();
        $this->post(route('admin.executives.from-election', $open))->assertNotFound();
    }
}
