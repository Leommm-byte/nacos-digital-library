<?php

namespace Tests\Feature\Elections;

use App\Enums\Role;
use App\Models\Election;
use App\Models\ElectionCandidate;
use App\Models\User;
use App\Support\Elections\ElectionLifecycle;
use App\Support\Elections\LiveResults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CandidatePhotoTest extends TestCase
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
    public function a_photo_is_stored_as_a_square_and_can_be_replaced_or_removed(): void
    {
        $election = Election::factory()->withBallot(['President' => 1])->create();
        $position = $election->positions()->firstOrFail();

        // A portrait photo is cropped to a square.
        $this->actingAs($this->admin)->post(route('elections.manage.candidates.store', [$election, $position]), [
            'name' => 'Ada Obi',
            'photo' => UploadedFile::fake()->image('ada.jpg', 900, 1200),
        ])->assertSessionHasNoErrors();

        $candidate = ElectionCandidate::where('name', 'Ada Obi')->firstOrFail();
        $first = (string) $candidate->photo_path;
        Storage::disk('private')->assertExists($first);
        $size = getimagesizefromstring((string) Storage::disk('private')->get($first));
        $this->assertIsArray($size);
        $this->assertSame([480, 480], [$size[0], $size[1]]);

        $this->get(route('elections.manage.show', $election))->assertOk()
            ->assertSee((string) $candidate->photoUrl($election), false)
            ->assertSee('Change photo');

        // A new photo replaces the old file.
        $this->travel(2)->seconds();
        $this->put(route('elections.manage.candidates.update', [$election, $position, $candidate]), [
            'name' => 'Ada Obi',
            'photo' => UploadedFile::fake()->image('new.png', 600, 600),
        ])->assertSessionHasNoErrors();
        $second = (string) $candidate->fresh()?->photo_path;
        $this->assertNotSame($first, $second);
        Storage::disk('private')->assertMissing($first);
        Storage::disk('private')->assertExists($second);

        // Saving without a photo keeps it; "Remove photo" deletes it.
        $this->put(route('elections.manage.candidates.update', [$election, $position, $candidate]), ['name' => 'Ada N. Obi']);
        $this->assertSame($second, $candidate->fresh()?->photo_path);

        $this->put(route('elections.manage.candidates.update', [$election, $position, $candidate]), ['name' => 'Ada N. Obi', 'remove_photo' => '1']);
        $this->assertNull($candidate->fresh()?->photo_path);
        Storage::disk('private')->assertMissing($second);
    }

    #[Test]
    public function only_images_are_accepted(): void
    {
        $election = Election::factory()->withBallot(['President' => 1])->create();
        $position = $election->positions()->firstOrFail();

        $this->actingAs($this->admin)->post(route('elections.manage.candidates.store', [$election, $position]), [
            'name' => 'Ada Obi',
            'photo' => UploadedFile::fake()->create('cv.pdf', 20, 'application/pdf'),
        ])->assertSessionHasErrorsIn('candidate-'.$position->id, 'photo');

        $this->assertDatabaseMissing('election_candidates', ['name' => 'Ada Obi']);
    }

    #[Test]
    public function photos_go_with_their_candidate_position_or_draft(): void
    {
        $election = Election::factory()->withBallot(['President' => 2, 'Treasurer' => 1])->create();
        [$first, $second] = $election->positions()->where('title', 'President')->firstOrFail()->candidates()->get()->all();
        $treasurer = $election->positions()->where('title', 'Treasurer')->firstOrFail();
        $third = $treasurer->candidates()->firstOrFail();
        $paths = [];

        foreach ([$first, $second, $third] as $candidate) {
            $paths[] = $path = "candidate-photos/{$election->id}/{$candidate->id}-x.jpg";
            Storage::disk('private')->put($path, 'jpeg');
            $candidate->update(['photo_path' => $path]);
        }

        $this->actingAs($this->admin)->delete(route('elections.manage.candidates.destroy', [$election, $first->position_id, $first]));
        Storage::disk('private')->assertMissing($paths[0]);
        Storage::disk('private')->assertExists($paths[1]);

        $this->delete(route('elections.manage.positions.destroy', [$election, $treasurer]));
        Storage::disk('private')->assertMissing($paths[2]);

        $this->delete(route('elections.manage.destroy', $election));
        Storage::disk('private')->assertMissing($paths[1]);
    }

    #[Test]
    public function photos_are_public_once_voting_opens(): void
    {
        $election = Election::factory()->withBallot(['President' => 2])->create();
        $candidate = $election->positions()->firstOrFail()->candidates()->firstOrFail();
        $path = "candidate-photos/{$election->id}/{$candidate->id}-x.jpg";
        Storage::disk('private')->put($path, (string) UploadedFile::fake()->image('a.jpg', 10, 10)->get());
        $candidate->update(['photo_path' => $path]);
        $url = (string) $candidate->photoUrl($election);

        // While the election is a draft, only admins see it.
        $this->get($url)->assertNotFound();
        $this->actingAs(User::factory()->create())->get($url)->assertNotFound();
        $this->actingAs($this->admin)->get($url)->assertOk();

        ElectionLifecycle::launch($election, 6);
        $this->app['auth']->forgetGuards();

        $response = $this->get($url)->assertOk();
        $this->assertStringContainsString('immutable', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('public', (string) $response->headers->get('Cache-Control'));

        // An old version is revalidated, not kept for a year.
        $stale = $this->get(route('elections.candidates.photo', [$election, $candidate, 'v' => 'old']))->assertOk();
        $this->assertStringContainsString('no-cache', (string) $stale->headers->get('Cache-Control'));

        // Only through its own election.
        $other = Election::factory()->open()->withBallot(['President' => 1])->create();
        $this->get(route('elections.candidates.photo', [$other, $candidate]))->assertNotFound();

        // On the ballot and in the results.
        $results = LiveResults::read($election->fresh() ?? $election);
        $this->assertNotNull($results);
        $this->assertSame($url, $results['positions'][0]['candidates'][0]['photo']);
        $this->get(route('elections.show', $election))->assertOk()->assertSee($url, false);

        $voter = User::factory()->create();
        $this->actingAs($voter)->get(route('elections.show', $election))->assertOk()->assertSee('data-ballot', false)->assertSee($url, false);
    }
}
